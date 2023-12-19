<?php namespace MarksTestModule;

/**
TODO
    add option for time range
        see stash
    need to account for start & end times, and split execution time up depending on date range
    show quick lists of current tops w/ links to stats for each showing hourly usage
        maybe not feasible
            line chart w/ area underneath broken up by one category at a time?  Can then drill down into that category to break it down further by another category?
    link to project from pid?
    order by sum(script_execution_time) desc
    For each line, should we show +/- for last hour, last X hours, yesterday, last week, last month?
    Include crons too (flight tracker uses a ton of CPU)?  They're not already included as requests are they?
        Put "(cron) after "Project" type like API
    Before committing, move to another module, review/rename everything?
    use a timed cron!  it will work just fine as-is in this case
    limit to last 24 hours, in case period of saved stats ever changes
    store daily via logs table?
        $r = $module->queryLogs('
                select usage_summary
                order by timestamp
                desc limit 1
            ', []);
    figure out size of summary, and clean up old logs after X time
    Could include module usage stats to show DB usage for settings, logs, etc.  Could find bad actors that add too many settings/logs, don't clean up old logs, etc.
    Could also include record stats records cache, maybe with "potential data points" by comparing to metadata table, w/ button to verify
    Could also include longest running and most often running (if busy every minute) crons
    Unit test this to make sure numbers are right?  I may have found an issue w/ the SQL queries, so errors are probably likely here as well
    Ask Scott about summarizing performance data
    Consider avoiding stats deletion for items in this query
        Query to figure out what percentage of rows would be left
        Multiple by percentage of table size to estimate space usage
            SELECT TABLE_NAME, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024) AS `Size (MB)`
            FROM
                information_schema.TABLES
            WHERE
                TABLE_name in ("redcap_log_view_requests", "redcap_log_view")
    Come up w/ basic plan for more historical data (run by Rob before executing)
        store & compare with history for previous days?
    Run by Rob
    Add title header above table
    add note saying requests & time are counted twice between different types (user/project/specificUrl/generalUrl)
        It is still useful to see different types side by side to determine top usage, but totals & percents will add up to more than 100% across types.
    Then move to REDCap core or its own module
    Remember
        Store 3 days instead of 1?  Maybe not... 1 day already takes up 250 gigs!
 */

const CPU_PERCENT_COLUMN_NAME = 'Percentage of Total CPU Time';

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
                    'CPU Time (hours)' => round($time/60/60, 2),
                    CPU_PERCENT_COLUMN_NAME => round($time/$totals['time']*100, 3),
                    'Request Count' => $requests,
                    'Percentage of All Requests' => round($requests/$totals['requests']*100, 3),
                    'Average Seconds Per Request' => round($time/$requests, 3),
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
        th:nth-child(1),
        td:nth-child(1){
            min-width: 60px;
        }

        th:nth-child(2),
        td:nth-child(2){
            max-width: 400px;
            overflow-wrap: break-word;
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