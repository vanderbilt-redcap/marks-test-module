<?php namespace MarksTestModule;

/**
 TODO
    Also consider showing requests with ridiculous counts?  Maybe there was some way to catch the "Failed API request" before they bloated the log for 3.5 years!
    Add option for type (to show only cron requests for example, like I needed when troubleshooting catmh issue)
    Detect when lines need to roll up under other lines
        Scenario
            All API usage is 20%
                Project 1 usage 2%
                Other 18%
            Project 1 usage is 5%
                API usage 2%
                UI usage 3%
        Or would it be better to have two modes (overall vs. specific)?
            Coming up with another scenario with multiple users & projects might help answer this
    Add links to user/project specific URLs?
    Review all lines
        Extract all language strings, and review them in the process
    Move to REDCap core (in redcap branch)
    Started via stash
        Bump stats up to 7 days to match cron history retention period.  Rob said it was OK.
            Prevent user from entering time range spanning cutoff where request traffic is deleted
                This relevant even if cutoff matches cron history
                Different tables get cleared at different time, so we might want to limit data to whichever table is newer
    Create PR for Rob
    Figure out goal next steps, and talk to Rob about them
        Maybe auto throttle after connection limit reached now?
    Unit test the queries?  The may still be inaccurate!
    Consider line charts?
        line chart w/ area underneath broken up by one category at a time?  Can then drill down into that category to break it down further by another category?
        alternatively, could keep current table and add linkes for each "Type" that display it over time (e.g. line graph, or table w/ totals by the hour)                
        If we don't hadd line charts, consider this:
            For each line, should we show +/- for last hour, last X hours, yesterday, last week, last month?
    Remember
        If we need to run summaries on a cron, and we're implementing this in a module
            use a timed cron!  it will work just fine as-is in this case
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
$includeIncompleteHttpRequests = isset($_GET['include-incomplete-http-requests']);
$threshold = htmlspecialchars($_GET['threshold'] ?? 1, ENT_QUOTES);

$getTops = function() use ($module, $startTime, $endTime, $threshold, $includeIncompleteHttpRequests){
    $excludeIncompleteHttpRequestsClause = $includeIncompleteHttpRequests ? '' : 'and r.script_execution_time is not null';

    $module->query('set @start = ?', $startTime);
    $module->query('set @end = ?', $endTime);

    $groups = [];
    $totals = [];

    $countCall = function($row, &$typeDetails) use ($startTime, $endTime){
        $overlap = min(strtotime($row['call_end']), strtotime($endTime)) - max(strtotime($row['call_start']), strtotime($startTime));
        $typeDetails['calls']++;
        $typeDetails['time'] += $overlap;
    };

    $userColumnName = 'User';
    $projectColumnName = 'Project';
    $moduleColumnName = 'Module Page';
    $specificURLColumnName = 'URL';
    $generalURLColumnName = 'Page';

    $result = $module->query("
        select
            user as '$userColumnName',
            p.project_id as '$projectColumnName',
            p.app_title,
            full_url as '$specificURLColumnName',
            page = 'api/index.php' as is_api,
            ts as call_start,
            date_add(ts, interval script_execution_time second) as call_end
        from (
            select
                r.log_view_id,
                ts,
                user,
                project_id,
                full_url,
                page,
                if(
                    script_execution_time,
                    script_execution_time,
                    timestampdiff(second, ts, now())
                ) as script_execution_time
            from redcap_log_view_requests r
            left join redcap_log_view v
                on v.log_view_id = r.log_view_id
            where
                r.log_view_id is not null
                $excludeIncompleteHttpRequestsClause
        ) r
        left join redcap_projects p
            on p.project_id = r.project_id
        where
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

            $countCall($row, $groups[$row['is_api']][$type][$identifier]);
        }

        $countCall($row, $totals);
    }

    $result = $module->query('
        select
            cron_name,
            directory_prefix,
            cron_run_start as call_start,
            cron_run_end as call_end
        from (
            select
                cron_id,
                cron_run_start,
                if(
                    cron_run_end,
                    cron_run_end,
                    now()
                ) as cron_run_end
            from redcap_crons_history h
        ) h
        join redcap_crons c
            on c.cron_id = h.cron_id
        left join redcap_external_modules m
            on m.external_module_id = c.external_module_id
        where
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
        $countCall($row, $details);
        $countCall($row, $totals);
    }

    if(empty($totals['time'])){
        // Allow for testing on localhost with minimal traffic totalling 0 seconds.
        return [];
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
                    $displayType = "API ($displayType)";
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
foreach(array_keys($tops[0] ?? []) as $i=>$column){
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

$addSplunkLink = function($text){
    if(isVanderbilt()){
        return "<a target='_blank' href='https://splunk.app.vumc.org/en-US/app/search/search?s=%2FservicesNS%2Fmceverm%2Fsearch%2Fsaved%2Fsearches%2FMost%2520active%2520REDCap%2520URLs%2520in%2520the%2520last%2520week&display.page.search.mode=fast&dispatch.sample_ratio=1&q=search%20index%3D%22victr_ori%22%20sourcetype%3Daccess_combined%0Ahost%20IN%20(ori1007lp%2C%20ori1008lp)%0A%7C%20rex%20field%3D_raw%20%22(%3Fms)%5E(%3FP%3Cclient_ip%3E(%5C%5Cd%7B1%2C3%7D%5C%5C.)%7B3%7D%5C%5Cd%7B1%2C3%7D)%5C%5Cs%2B(%3FP%3Cremote_logname%3E%5B%5C%5Cw-%5D%2B)%5C%5Cs%2B(%3FP%3Cremote_username%3E%5B%5C%5Cw-%5D%2B)%5C%5Cs(%3FP%3Ctimestamp%3E%5C%5C%5B%5C%5Cd%7B1%2C2%7D%2F%5C%5Cw%2B%2F20%5C%5Cd%7B2%7D%3A%5C%5Cd%7B2%7D%3A%5C%5Cd%7B2%7D%3A%5C%5Cd%7B2%7D%5C%5Cs%5C%5C-%5C%5Cd%7B4%7D%5C%5C%5D)%5C%5Cs%5C%22(%3FP%3Crequest_method%3E%5BA-Z%5D%2B)%5C%5Cs(%3FP%3Crequest_first_line%3E%5B%5E%5C%22%5D%2B%5C%5Cw%2B%5B%5E%5C%22%5D%2B)%5C%22%5C%5Cs(%3FP%3Cresponse%3E%5C%5Cd%7B1%2C4%7D)%5C%5Cs(%3FP%3Cresponse_bytes%3E%5B%5C%5Cd-%5D%2B)%5C%5Cs(%3FP%3Cresponse_time%3E%5C%5Cd%2B)ms%5C%5Cs(%3FP%3Cresponse_pid%3E%5C%5Cd%2B)%5C%5Cs%5C%22(%3FP%3Creferrer%3E%5B%5E%5C%22%5D%2B)%5C%22%5C%5Cs%5C%22(%3FP%3Cuser_agent%3E%5B%5E%5C%22%5D%2B)%5C%22%22%20offset_field%3D_extracted_fields_bounds%20%7C%20rex%20field%3D_raw%20%22%5E(%3F%3A%5B%5E%3D%5C%5Cn%5D*%3D)%7B3%7D(%3FP%3Cid%3E%5B%5E%5C%22%5D%2B)%22%20offset_field%3D_extracted_fields_bounds0%20%7C%20rex%20field%3D_raw%20%22%5E(%3FP%3Cip%3E%5B%5E%20%5D%2B)%22%20offset_field%3D_extracted_fields_bounds1%20%7C%20rex%20field%3D_raw%20%22%5E(%3FP%3Cclient_ip%3E(%5C%5Cd%7B1%2C3%7D%5C%5C.)%7B3%7D%5C%5Cd%7B1%2C3%7D)%5C%5Cs%2B(%3FP%3Cremote_logname%3E%5B%5C%5Cw-%5D%2B)%5C%5Cs%2B(%3FP%3Cremote_username%3E%5B%5C%5Cw-%5D%2B)%5C%5Cs(%3FP%3Ctimestamp%3E%5C%5C%5B%5C%5Cd%7B1%2C2%7D%2F%5C%5Cw%2B%2F20%5C%5Cd%7B2%7D%3A%5C%5Cd%7B2%7D%3A%5C%5Cd%7B2%7D%3A%5C%5Cd%7B2%7D%5C%5Cs%5C%5C-%5C%5Cd%7B4%7D%5C%5C%5D)%5C%5Cs%5C%22(%3FP%3Crequest_method%3E%5BA-Z%5D%2B)%5C%5Cs(%3FP%3Crequest_first_line%3E%5B%5E%5C%22%5D%2B%5C%5Cw%2B%5B%5E%5C%22%5D%2B)%5C%22%5C%5Cs(%3FP%3Cresponse%3E%5C%5Cd%7B1%2C4%7D)%5C%5Cs(%3FP%3Cresponse_bytes%3E%5B%5C%5Cd-%5D%2B)%5C%5Cs(%3FP%3Cresponse_time%3E%5C%5Cd%2B)ms%5C%5Cs(%3FP%3Cresponse_pid%3E%5C%5Cd%2B)%5C%5Cs%5C%22(%3FP%3Creferrer%3E%5B%5E%5C%22%5D%2B)%5C%22%5C%5Cs%5C%22(%3FP%3Cuser_agent%3E%5B%5E%5C%22%5D%2B)%5C%22%22%20offset_field%3D_extracted_fields_bounds2%20%7C%20rex%20field%3D_raw%20%22%5E(%3FP%3Csrc_ip%3E%5B%5E%5C%5C-%5D%2B)%22%20offset_field%3D_extracted_fields_bounds3%0A%7C%20eval%20request_first_line%3Dsubstr(request_first_line%2C%201%2C%20200)%20%7C%20eval%20minutes%3Dresponse_time%2F1000%2F60%20%7C%20stats%20count(request_first_line)%20as%20requests%2C%20sum(response_time)%2C%20sum(minutes)%20as%20total_minutes%20BY%20request_first_line%20%7C%20eval%20seconds_per_request%3Dround(total_minutes*60%2Frequests%2C%203)%20%7C%20eval%20total_minutes%3Dround(total_minutes%2C%201)%20%7C%20sort%20-total_minutes&earliest=-7d%40h&latest=now&sid=1704134165.965092_F6F4DD87-E686-4BEA-8022-198EA70D6DAF'>$text</a>";
    }
    else{
        return $text;
    }
};

?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js" integrity="sha512-BkpSL20WETFylMrcirBahHfSnY++H2O1W+UnEEO4yNIl+jI2+zowyoGJpbtk6bx97fBXf++WJHSSK2MV4ghPcg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<div id='datacore-customizations-module-container'>
    <h4>Top Resource Usage Report</h4>
    <p>Things to remember when interpreting these stats:</p>
    <ul>
        <li>CPU usage & call counts track most but not all HTTP requests. See <?=$addSplunkLink("your web server's logs")?> for ALL requests.</li>
        <li>Hours & percentages represent an accurate portion of the total, but are duplicated between "Types" (may add up to more than 100%).  For example, if a user makes API requests on a single project totaling 70% of the server's CPU usage, seperate lines for the user AND project will be included (both showing 70%).  Aggregating stats like this is important when usage within a single "Type" becomes excessive (e.g. one user across many projects, or many users on one project).</li>
        <li><a target='_blank' href='https://github.com/vanderbilt-redcap/external-module-framework-docs/blob/main/crons.md#timed-crons-deprecated'>External Module Timed Crons</a> are not currently included in these status.
    </ul>
    <div class='controls'>
        <label>Start Time:</label><input name='start-time' type='datetime-local' value='<?=$startTime?>'><br>
        <label>End Time:</label><input name='end-time' type='datetime-local' value='<?=$endTime?>'><br>
        <input name='include-incomplete-http-requests' type='checkbox' <?php if($includeIncompleteHttpRequests) echo 'checked'?>><label>Include incomplete HTTP requests</label>
        <a href="javascript:;" class="help" onclick="
            simpleDialog('This includes both requests that are still running AND requests that completed but did not store a script_execution_time (skewing results).  We might be able to store script_execution_time in more scenarios in order to accurately measure HTTP requests that are still running.  Currently running crons are always included regardless of this setting.');
        ">?</a><br>
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
        margin-top: 25px;
        margin-bottom: -20px;

        label{
            display: inline-block;
            min-width: 75px;
        }

        input[type=checkbox]{
            margin-right: 5px;
            vertical-align: -2px;
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

        a{
            text-decoration: underline;
        }
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
            if(
                !input.name
                ||
                (
                    input.type === 'checkbox'
                    &&
                    !input.checked
                )
            ){
                params.delete(input.name)
            }
            else{
                params.set(input.name, input.value)
            }
        })

        location.search = params
    }
})()
</script>