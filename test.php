<?php namespace MarksTestModule;

/**
TODO
    move "API" before user/project
    add support for requests & crons (especially) in-transit?
    Add title header above table
    add note saying requests & time are counted twice between different types (user/project/specificUrl/generalUrl)
        It is still useful to see different types side by side to determine top usage, but totals & percents will add up to more than 100% across types.
    add tool tip saying percentages are not exact (not accounting for some requests, see at VUMC Splunk for stats on all requests)
    There are still two /api urls sometimes
    Come up w/ basic plan for more historical data (run by Rob before executing)
        Consider avoiding stats deletion for items in this query
        Query to figure out what percentage of rows would be left
        Multiple by percentage of table size to estimate space usage
            SELECT TABLE_NAME, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024) AS `Size (MB)`
            FROM
                information_schema.TABLES
            WHERE
                TABLE_name in ("redcap_log_view_requests", "redcap_log_view")    
        store & compare with history for previous days?
        store daily via logs table?
            $r = $module->queryLogs('
                    select usage_summary
                    order by timestamp
                    desc limit 1
                ', []);
        figure out size of summary, and clean up old logs after X time
        Ask Scott about summarizing performance data
    Run by Rob
    Prevent user from entering time range spanning cutoff where request traffic is deleted, but cron history remains
    Review all lines, rename any language
    Move to REDCap core or its own module
    Unit test the queries?  The may still be inaccurate!
    if/when we want a more advanced interface
        show quick lists of current tops w/ links to stats for each showing hourly usage
            maybe not feasible
                line chart w/ area underneath broken up by one category at a time?  Can then drill down into that category to break it down further by another category?
        For each line, should we show +/- for last hour, last X hours, yesterday, last week, last month?
    Remember
        If we need to run summaries on a cron, and we're implementing this in a module
            use a timed cron!  it will work just fine as-is in this case
        Store 3 days instead of 1?  Maybe not... 1 day already takes up 250 gigs!
        down the road
            Could include module usage stats to show DB usage for settings, logs, etc.  Could find bad actors that add too many settings/logs, don't clean up old logs, etc.
            Could also include record stats records cache, maybe with "potential data points" by comparing to metadata table, w/ button to verify
 */

const CPU_PERCENT_COLUMN_NAME = 'Percent of Total CPU Time';


$now = time();
$oneHour = 60*60;
$oneHourAgo = $now - $oneHour;
$format = 'Y-m-d\\TH:i';

$startTime = htmlspecialchars($_GET['start-time'] ?? date($format, $oneHourAgo), ENT_QUOTES);
$endTime = htmlspecialchars($_GET['end-time'] ?? date($format, $now), ENT_QUOTES);
$threshold = htmlspecialchars($_GET['threshold'] ?? 1, ENT_QUOTES);

$getTops = function() use ($module, $startTime, $endTime, $threshold){
    $module->query('set @start = ?', $startTime);
    $module->query('set @end = ?', $endTime);

    $userColumnName = 'User';
    $projectColumnName = 'Project';
    $moduleColumnName = 'Module Page';
    $specificURLColumnName = 'URL';
    $generalURLColumnName = 'Page';

    $result = $module->query("
        select
            v.user as '$userColumnName',
            v.project_id as '$projectColumnName',
            p.app_title,
            full_url as '$specificURLColumnName',
            page = 'api/index.php' as is_api,
            timestampdiff(
                second,
                if(
                    ts > @start,
                    ts,
                    @start
                ),
                if(
                    date_add(ts, interval script_execution_time second) < @end,
                    date_add(ts, interval script_execution_time second),
                    @end
                )
            ) as duration
        from redcap_log_view_requests r
        left join redcap_log_view v
            on v.log_view_id = r.log_view_id
        left join redcap_projects p
            on p.project_id = v.project_id
        where
            r.script_execution_time is not null
            and v.log_view_id is not null
            and
            (
                (
                    ts >= @start
                    and
                    ts < @end
                )
                or
                (
                    date_add(ts, interval script_execution_time second) > @start
                    and
                    date_add(ts, interval script_execution_time second) <= @end
                )
            )
    ", []);
    
    $groups = [];
    $totals = [];
    while($row = $result->fetch_assoc()){
        $types = [
            $userColumnName,
            $projectColumnName,
            $moduleColumnName,
            $specificURLColumnName,
            $generalURLColumnName,
        ];

        foreach($types as $type){
            if($type === $moduleColumnName){
                $parts = explode('prefix=', $row[$specificURLColumnName]);
                if(count($parts) === 1){
                    // This is not a module specific request
                    continue;
                }

                $prefix = explode('&', $parts[1])[0];
                $identifier = $prefix;
            }
            else if(in_array($type, [$specificURLColumnName, $generalURLColumnName])){
                $identifier = $row[$specificURLColumnName];
                $identifier = str_replace(APP_PATH_WEBROOT_FULL, '/', $identifier);
                $identifier = str_replace(APP_PATH_WEBROOT, '/', $identifier);

                $parts = explode('?', $identifier);
                if($type === $generalURLColumnName){
                    $identifier = $parts[0];
                }
                else if(count($parts) === 1){
                    /**
                     * This URL doesn't have params, and will already be counted as a general URL.
                     * It's confusing to count it again as a specific URL, so skip it.
                     */
                    continue;
                }
            }
            else{
                $identifier = $row[$type];

                if(empty($identifier) && in_array($type, [$userColumnName, $projectColumnName])){
                    // Only count these lines for other identifiers
                    continue;
                }
                else if($type === $projectColumnName){
                    $identifier = $row['app_title'] . " ($identifier)";
                }
            }

            $details = &$groups[$row['is_api']][$type][$identifier];
            $details['calls']++;
            $details['time'] += $row['duration'];
        }

        $totals['calls']++;
        $totals['time'] += $row['duration'];
    }

    $result = $module->query('
        select
            cron_name,
            directory_prefix,
            timestampdiff(
                second,
                if(
                    cron_run_start > @start,
                    cron_run_start,
                    @start
                ),
                if(
                    cron_run_end < @end,
                    cron_run_end,
                    @end
                )
            ) as duration
        from redcap_crons_history h
        join redcap_crons c
            on c.cron_id = h.cron_id
        left join redcap_external_modules m
            on m.external_module_id = c.external_module_id
        where
            cron_run_end is not null
            and
            (
                (
                    cron_run_start >= @start
                    and
                    cron_run_start < @end
                )
                or
                (
                    cron_run_end > @start
                    and
                    cron_run_end <= @end
                )
            )
    ', []);

    while($row = $result->fetch_assoc()){
        $cronName = $row['cron_name'];
        $prefix = $row['directory_prefix'] ?? 'REDCap';
        $identifier = "$prefix - $cronName";

        $details = &$groups[false]['Cron'][$identifier];
        $details['calls']++;
        $details['time'] += $row['duration'];

        $totals['calls']++;
        $totals['time'] += $row['duration'];
    }

    $thresholdTime = $totals['time'] * $threshold/100;
    $tops = [];
    foreach($groups as $isApi=>$types){
        foreach($types as $type=>$identifiers){
            foreach($identifiers as $identifier=>$details){
                if($details['time'] < $thresholdTime){
                    continue;
                }

                $calls = $details['calls'];
                $time = $details['time'];
                $displayType = $type;

                if($isApi && in_array($type, [$userColumnName, $projectColumnName])){
                    $displayType = "$displayType (API)";
                }

                $tops[] = [
                    'Type' => $displayType,
                    'Identifier' => $identifier,
                    'CPU Time (hours)' => number_format($time/60/60, 1),
                    CPU_PERCENT_COLUMN_NAME => number_format($time/$totals['time']*100, 1) . '%',
                    'Call Count' => number_format($calls),
                    'Percent of Total Calls' => number_format($calls/$totals['calls']*100, 1) . '%',
                    'Average Seconds Per Call' => number_format($time/$calls, 1),
                ];
            }
        }
    }

    return $tops;
};

$tops = $getTops();
$columns = [];
$sortColumn = 0;
foreach(array_keys($tops[0]) as $i=>$column){
    $columns[] = [
        'title' => $column,
        'orderSequence' => ['desc', 'asc'],
    ];

    if($column === CPU_PERCENT_COLUMN_NAME){
        $sortColumn = $i;
    }
}

$rows = [];
foreach($tops as $top){
    $rows[] = array_values($top);
}

?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js" integrity="sha512-BkpSL20WETFylMrcirBahHfSnY++H2O1W+UnEEO4yNIl+jI2+zowyoGJpbtk6bx97fBXf++WJHSSK2MV4ghPcg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<div id='datacore-customizations-module-container'>
    <div class='controls'>
        <label>Start Time:</label><input name='start-time' type='datetime-local' value='<?=$startTime?>'><br>
        <label>End Time:</label><input name='end-time' type='datetime-local' value='<?=$endTime?>'><br>
        Display calls using at least <input name='threshold' value='<?=$threshold?>' style='width: 26px; text-align: right'>% of total CPU time
        <a href="javascript:;" class="help" onclick="
            simpleDialog('CPU time is often a reasonable proxy for DB load. The only known significant exceptions are Flight Tracker crons (which use sleep calls).');
        ">?</a>
        <br><br>
        <button>Apply</button>
    </div>
    <table></table>
</div>

<style>
    #pagecontainer{
        max-width: 1350px;
    }

    #datacore-customizations-module-container .controls{
        position: relative;
        z-index: 10;
        margin-bottom: -20px;

        label{
            display: inline-block;
            min-width: 75px;
        }
    }

    #datacore-customizations-module-container{
        th{
            padding-right: 15px;
            max-width: 55px;
        }
        
        td:nth-child(1){
            white-space: nowrap;
        }

        th:nth-child(2),
        td:nth-child(2){
            max-width: 375px;
            overflow-wrap: break-word;
        }

        td:nth-child(n+3){
            text-align: right;
            padding-right: 28px;
        }
    }

    #datacore-customizations-module-container td a{
        text-decoration: underline;
    }
</style>

<script>
(() => {
    const container = document.querySelector('#datacore-customizations-module-container')

    const columns = <?=json_encode($module->escape($columns))?>;
    if(columns.length === 0){
        // At least one column is required for the table to render
        columns.push({})
    }
    else{
        columns[1].render = (data, type, row, meta) => {
            if(row[0].startsWith('Project')){
                const pid = data.split('(')[1].split(')')[0]
                data = '<a target="_blank" href="<?=APP_PATH_WEBROOT?>' + 'index.php?pid=' + pid + '">' + data + '</a>'
            }

            return data
        }
    }

    $(container.querySelector('table')).DataTable({
        columns: columns,
        data: <?=json_encode($module->escape($rows))?>,
        order: [[<?=$sortColumn?>, 'desc']],
        paging: false,
        language: {
            emptyTable: 'No calls found over minimum reporting threshold.',
        },
    })

    const controls = container.querySelector('.controls')
    controls.querySelector('button').onclick = () => {
        showProgress(true)
        const params = new URLSearchParams(location.search)

        controls.querySelectorAll('input').forEach((input) => {
            if(!input.name){
                return
            }

            params.set(input.name, input.value)
        })

        location.search = params
    }
})()
</script>