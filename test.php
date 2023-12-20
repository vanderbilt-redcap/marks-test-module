<?php namespace MarksTestModule;

/**
TODO
    make sure cron numbers are accurate
    Change "request" wording to "call" to better account for crons
    normalize numbers (add right padded zero, etc.)
    Change Module to Module Page & expand column a little?
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
    add option for time range
        see stash
    need to account for start & end times, and split execution time up depending on date range
    add support for requests & crons (especially) in-transit?
    Add title header above table
    add note saying requests & time are counted twice between different types (user/project/specificUrl/generalUrl)
        It is still useful to see different types side by side to determine top usage, but totals & percents will add up to more than 100% across types.
    add tool tip saying percentages are not exact (not accounting for some requests, see at VUMC Splunk for stats on all requests)
    Review all lines, rename any language
    Move to REDCap core or its own module
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

$getTops = function() use ($module){
    $userColumnName = 'User';
    $projectColumnName = 'Project';
    $moduleColumnName = 'Module';
    $specificURLColumnName = 'Specific URL';
    $generalURLColumnName = 'General URL';

    $result = $module->query("
        select
            v.user as '$userColumnName',
            v.project_id as '$projectColumnName',
            p.app_title,
            full_url as '$specificURLColumnName',
            page = 'api/index.php' as is_api,
            script_execution_time
        from redcap_log_view_requests r
        left join redcap_log_view v
            on v.log_view_id = r.log_view_id
        left join redcap_projects p
            on p.project_id = v.project_id
        where
            r.script_execution_time is not null
            and v.log_view_id is not null
            and ts > DATE_SUB(now(), interval 1 hour)
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
            else if($type === $generalURLColumnName){
                $parts = explode('?', $row[$specificURLColumnName]);
                if(count($parts) === 1){
                    // This URL doesn't have params, and will already be counted as a specific URL
                    continue;
                }

                $identifier = $parts[0];
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
            $details['requests']++;
            $details['time'] += $row['script_execution_time'];
        }

        $totals['requests']++;
        $totals['time'] += $row['script_execution_time'];
    }

    $result = $module->query('
        select
            cron_name,
            directory_prefix,
            timestampdiff(second, cron_run_start, cron_run_end) as duration
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
                    cron_run_start > DATE_SUB(now(), interval 1 hour)
                    and
                    cron_run_start < now()
                )
                or
                (
                    cron_run_end > DATE_SUB(now(), interval 1 hour)
                    and
                    cron_run_end < now()
                )
            )
    ', []);

    while($row = $result->fetch_assoc()){
        $cronName = $row['cron_name'];
        $prefix = $row['directory_prefix'] ?? 'REDCap';
        $identifier = "$prefix.$cronName";

        $details = &$groups[false]['Cron'][$identifier];
        $details['requests']++;
        $details['time'] += $row['duration'];

        $totals['requests']++;
        $totals['time'] += $row['duration'];
    }

    $threshold = $totals['time']/100;
    $tops = [];
    foreach($groups as $isApi=>$types){
        foreach($types as $type=>$identifiers){
            foreach($identifiers as $identifier=>$details){
                if($details['time'] < $threshold){
                    continue;
                }

                $requests = $details['requests'];
                $time = $details['time'];
                $displayType = $type;

                if($isApi && in_array($type, [$userColumnName, $projectColumnName])){
                    $displayType = "$displayType (API)";
                }
                else if(in_array($type, [$specificURLColumnName, $generalURLColumnName])){
                    $displayType = 'URL';
                    $identifier = str_replace(APP_PATH_WEBROOT_FULL, '/', $identifier);
                    $identifier = str_replace(APP_PATH_WEBROOT, '/', $identifier);
                }

                $tops[] = [
                    'Type' => $displayType,
                    'Identifier' => $identifier,
                    'CPU Time (hours)' => round($time/60/60, 1),
                    CPU_PERCENT_COLUMN_NAME => round($time/$totals['time']*100, 1) . '%',
                    'Request Count' => $requests,
                    'Percent of Total Requests' => round($requests/$totals['requests']*100, 1) . '%',
                    'Average Seconds Per Request' => round($time/$requests, 1),
                ];
            }
        }
    }

    return $tops;
};

$tops = $getTops();
if(empty($tops)){
    die('No usage found over minimum reporting threshold.');
}

$columns = [];
foreach(array_keys($tops[0]) as $i=>$column){
    $columns[] = ['title' => $column];

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
    <table></table>
</div>

<style>
    #pagecontainer{
        max-width: 1500px;
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

    columns[1].render = (data, type, row, meta) => {
        if(row[0].startsWith('Project')){
            const pid = data.split('(')[1].split(')')[0]
            data = '<a target="_blank" href="<?=APP_PATH_WEBROOT?>' + 'index.php?pid=' + pid + '">' + data + '</a>'
        }

        return data
    }

    $(container.querySelector('table')).DataTable({
        columns: columns,
        data: <?=json_encode($module->escape($rows))?>,
        order: [[<?=$sortColumn?>, 'desc']],
        paging: false,
    })
})()
</script>