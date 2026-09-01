<script>

    $( document ).ready(function() {

        $("#{{formGridTunnel['table_id']}}").UIBootgrid({
            search:'/api/ipsecwatchdog/tunnel/search/',
            get:'/api/ipsecwatchdog/tunnel/get/',
            add:'/api/ipsecwatchdog/tunnel/add/',
            set:'/api/ipsecwatchdog/tunnel/set/',
            del:'/api/ipsecwatchdog/tunnel/del/',
            toggle:'/api/ipsecwatchdog/tunnel/toggle/'
        });

        $("#runAct").click(function(){
            $("#runOutput").addClass("hidden");
            ajaxCall(url="/api/ipsecwatchdog/service/run", sendData={}, callback=function(data,status){
                $("#runOutput").removeClass("hidden").text(data.output);
            });
        });

        $("#statusAct").click(function(){
            var $msg = $("#statusMessage").addClass("hidden");
            var $table = $("#statusTable").addClass("hidden");
            var $raw = $("#statusRawOutput").addClass("hidden");
            $("#statusContainer").removeClass("hidden");
            $("#toggleRawStatus").addClass("hidden");

            ajaxCall(url="/api/ipsecwatchdog/service/status", sendData={}, callback=function(data,status){
                $raw.text(data.output || "");
                $("#toggleRawStatus").removeClass("hidden");

                var rows = (data && data.rows) ? data.rows : [];
                if (data && data.daemon_running === false) {
                    $msg.text("{{ lang._('IPsec service does not appear to be running.') }}").removeClass("hidden");
                } else if (rows.length === 0) {
                    $msg.text("{{ lang._('No active IPsec tunnels.') }}").removeClass("hidden");
                } else {
                    var $tbody = $("#statusTableBody").empty();
                    $.each(rows, function(i, row){
                        $tbody.append(
                            $("<tr>").append(
                                $("<td>").text(row.connection_label || row.connection),
                                $("<td>").text(row.ike_state),
                                $("<td>").text(row.child_label || row.child),
                                $("<td>").text(row.child_state)
                            )
                        );
                    });
                    $table.removeClass("hidden");
                }
            });
        });

        $("#toggleRawStatus").click(function(e){
            e.preventDefault();
            $("#statusRawOutput").toggleClass("hidden");
        });

    });

</script>

<div class="content-box __mb">
    {{ partial('layout_partials/base_bootgrid_table', formGridTunnel) }}
</div>

<div class="content-box" style="margin-top: 15px; padding: 10px;">
    <button class="btn btn-default" id="runAct" type="button">{{ lang._('Run watchdog now') }}</button>
    <button class="btn btn-default" id="statusAct" type="button">{{ lang._('Show tunnel status') }}</button>
    <div class="text-muted" style="margin-top: 5px;"><small>{{ lang._('Runs the check/reconnect pass for every enabled tunnel above; the same pass the scheduled cron job runs.') }}</small></div>
    <pre id="runOutput" class="hidden" style="margin-top: 15px;"></pre>
    <div class="hidden" id="statusContainer" style="margin-top: 15px;">
        <div id="statusMessage" class="text-muted hidden"></div>
        <table class="table table-striped table-condensed hidden" id="statusTable">
            <thead>
                <tr>
                    <th>{{ lang._('Connection') }}</th>
                    <th>{{ lang._('IKE state') }}</th>
                    <th>{{ lang._('Child SA') }}</th>
                    <th>{{ lang._('Child state') }}</th>
                </tr>
            </thead>
            <tbody id="statusTableBody"></tbody>
        </table>
        <a href="#" id="toggleRawStatus" class="hidden"><small>{{ lang._('show/hide raw output') }}</small></a>
        <pre id="statusRawOutput" class="hidden" style="margin-top: 10px;"></pre>
    </div>
</div>

{{ partial("layout_partials/base_dialog",['fields':formDialogTunnel,'id':formGridTunnel['edit_dialog_id'],'label':lang._('Edit Tunnel')])}}
