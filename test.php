<?php

/**
 * TODO
 *      Why does keep alive show up under general & specific
 *      Finalize datatable
 *      add filter for type (user/project/url/url w/o params)
 *      show quick lists of current tops w/ links to stats for each showing hourly usage
 *          maybe not feasible
 *              line chart w/ area underneath broken up by one category at a time?  Can then drill down into that category to break it down further by another category?
 *      show to Rob to see if expanding to days would be useful
 *      store & compare with history for previous days
 *      link to project from pid?
 *      order by sum(script_execution_time) desc
 *      For each line, should we show +/- for last hour, last X hours, yesterday, last week, last month?
 *      Before committing, move to another module, review/rename everything?
 *      use a timed cron!  it will work just fine as-is in this case
 *      limit to last 24 hours, in case period of saved stats ever changes
 *      store daily via logs table?
 *          $r = $module->queryLogs('
                select usage_summary
                order by timestamp
                desc limit 1
            ', []);
 *      figure out size of summary, and clean up old logs after X time
 *      Could include module usage stats to show DB usage for settings, logs, etc.  Could find bad actors that add too many settings/logs, don't clean up old logs, etc.
 *      Could also include record stats records cache, maybe with "potential data points" by comparing to metadata table, w/ button to verify
 *      Could also include longest running and most often running (if busy every minute) crons
 *      Unit test this to make sure numbers are right?  I may have found an issue w/ the SQL queries, so errors are probably likely here as well
 *      summary table
 *          Can I use log table for this, so no need to create another table?!?
 *          date, user, project, url, url_without_params, is_api
 *          Would it be too much 
 */

$getTops = function() use ($module){
    $userColumnName = 'User';
    $projectColumnName = 'Project';
    $specificURLColumnName = 'Specific URL';
    $generalURLColumnName = 'General URL';

    $result = $module->query("
        select
            v.user as '$userColumnName',
            v.project_id as '$projectColumnName',
            full_url as '$specificURLColumnName',
            substring_index(full_url,'?',1) as '$generalURLColumnName',
            page = 'api/index.php' as is_api,
            script_execution_time
        from redcap_log_view_requests r
        left join redcap_log_view v
            on v.log_view_id = r.log_view_id
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
            $specificURLColumnName,
            $generalURLColumnName,
        ];

        foreach($types as $type){
            $details = &$groups[$row['is_api']][$type][$row[$type]];
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

                if(in_array($type, [$userColumnName, $projectColumnName])){
                    $identifier = "$type: $identifier";
                }

                $tops[] = [
                    'isApi' => $isApi,
                    'identifier' => $identifier,
                    'requests' => $requests,
                    'time' => $time,
                    'CPU Time In Hours' => round($time/60/60, 2),
                    'Percentage of Total CPU Time' => round($time/$totals['time']*100, 3),
                    'Requests' => $requests,
                    'Percentage of Requests' => round($requests/$totals['requests']*100, 3),
                    'Average Seconds Per Request' => round($time/$requests, 3),
                ];
            }
        }
    }
    
    return $tops;
};

$tops = $getTops();

$columns = [];
foreach(array_keys($tops[0])as $column){
    $columns[] = ['title' => $column];
}

$rows = [];
foreach($tops as $top){
    $rows[] = array_values($top);
}

$module->initializeJavascriptModuleObject();
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js" integrity="sha512-BkpSL20WETFylMrcirBahHfSnY++H2O1W+UnEEO4yNIl+jI2+zowyoGJpbtk6bx97fBXf++WJHSSK2MV4ghPcg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<div id='datacore-customizations-module-container'>
    <table width="100%"></table>
</div>

<script>
(() => {
    const module = <?=$module->getJavascriptModuleObjectName()?>;
    const container = document.querySelector('#datacore-customizations-module-container')

    $(container.querySelector('table')).DataTable({
        columns: <?=json_encode($columns)?>,
        data: <?=json_encode($rows)?>
    })
})()
</script>