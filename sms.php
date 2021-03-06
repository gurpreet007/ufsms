<?php
  session_start();

  function dbConnect() {
    #$host   = 'localhost:unifresh_local';
    $host   = 'newsrv:unifresh';
    $dbuser = 'sysdba';
    $dbpass = 'masterkey';

    $dbh = ibase_connect($host, $dbuser, $dbpass) or 
      die(ibase_errmsg());
    return $dbh;
  }

  function dbClose($dbh) {
    ibase_close($dbh);
  }

  function flash($msg, $type="alert-success") {
    $strongStr = "Info!";
    switch($type) {
      case "alert-success":
        $strongStr = "Success!";
        break;
      case "alert-danger":
        $strongStr = "Error!";
        break;
    }
    echo <<<EOD
    <div class='alert $type' alert-dismissible fade slow role='alert'> 
      <strong>$strongStr</strong> $msg 
      <button type='button' class='close' 
        data-dismiss='alert' aria-label='Close'>
        <span aria-hidden='true'>&times;</span>
      </button>
    </div>
EOD;
  }

  function getAuthHTTPHeader($method, $action) {
    $ts = date_timestamp_get(date_create());
    $nonce = bin2hex(random_bytes(10));
    $http_host = "api.smsglobal.com";
    $http_port = "443";
    $opt_data = "";

    $key = "3390406d124c45b8bfc81d217f5c24dd";
    $secret = "d8309437584aafd48f7231368edffbbb";

    $concat_str = sprintf("%s\n%s\n%s\n%s\n%s\n%s\n%s\n",
      $ts, $nonce, $method, $action, $http_host, $http_port, $opt_data);

    $sig = hash_hmac("sha256", $concat_str, $secret, true);

    $hash = base64_encode($sig);

    $mac = sprintf('MAC id="%s", ts="%s", nonce="%s", mac="%s"', 
      $key, $ts, $nonce, $hash);

    return $mac;
  }

  function getMessageStatus($outgoingID) {
    $action = "/v2/sms/$outgoingID";
    $crl = curl_init("https://api.smsglobal.com".$action);
    $header = [ 'Content-type: application/json',
                'Accept: application/json',
                'Authorization: '. getAuthHTTPHeader("GET", $action)];
    curl_setopt($crl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($crl, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($crl, CURLOPT_RETURNTRANSFER, true);

    $rest = curl_exec($crl) or die(curl_error($crl));

    curl_close($crl);
 
    $json = json_decode($rest, true);
    echo "<pre>";
    print_r($json);
    echo "</pre>";

    #json structure differs if message consists of more chars 
    #than can fit in a single message
    $status = array_key_exists("messages", $json)? 
                $json["messages"][0]["status"] : $json["status"];
    echo $status;

    return $status;
  }

  function sendMessage($msg, $arrMobs) {
    $debug = false;

    $action = "/v2/sms/";
    $crl = curl_init("https://api.smsglobal.com".$action);
    $header = [ 'Content-type: application/json',
                'Accept: application/json',
                'Authorization: '. getAuthHTTPHeader("POST", $action)];
    curl_setopt($crl, CURLOPT_HTTPHEADER, $header);

    $arrMobs = array_unique($arrMobs);
    if($debug) {
      echo "<br>Orig Mobs:";
      print_r($arrMobs);
    }

    $arrPrunedMobs = [];
    foreach($arrMobs as $mob) {
      $arrPrunedMobs[] = preg_replace("/[ a-zA-Z-.()]/", "", $mob);
    }
    if($debug) {
      echo  "<br>Pruned Mobs:";
      print_r($arrPrunedMobs);
    }

    $data = [ 
              'destinations'  => $arrPrunedMobs,
              'origin'        => '',
              'message'       => $msg,
              'sharedPool'    =>  '',
            ];
    curl_setopt($crl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($crl, CURLOPT_POST, true);
    curl_setopt($crl, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($crl, CURLOPT_RETURNTRANSFER, true);
    $rest = curl_exec($crl) or die(curl_error($crl));
    curl_close($crl);
 
    $json = json_decode($rest, true);
    if($debug) {
      echo "<br>Rest JSON:<pre>";
      print_r($json);
      echo "</pre>";
    }
  
    #json structure differs if message consists of more chars 
    #than can fit in a single message
    $status = array_key_exists("messages", $json)? 
                $json["messages"][0]["status"] : $json["status"];
    $outgoingID = array_key_exists("messages", $json)? 
                $json["messages"][0]["outgoing_id"] : $json["outgoing_id"];

    return ["status"=>$status, "outgoingID"=>$outgoingID];
  }

  function sendMessageUsingEmail($msg, $mobs) {
    $emailIDs = "";
    $domainName = "email.smsglobal.com";
    $from = "gurpreet.singh@unifresh.com.au";

    if(gettype($mobs) === "array") {
      $mobs = array_unique($mobs);
      foreach($mobs as $mob) {
        $prunedMob =  preg_replace("/[ a-zA-Z-.()]/", "", $mob);
        $emailIDs .= "$prunedMob@$domainName,";
      }
    }
    else if(gettype($mobs) === "string") { 
      $prunedMob =  preg_replace("/[ a-zA-Z-.()]/", "", $mobs);
      $emailIDs = "$prunedMob@$domainName";
    }
    else
      return false;

    echo "Email IDs: $emailIDs";
    $postData  =  "";
    $postData .=  "toEmail="      . rawurlencode($emailIDs);
    $postData .=  "&fromEmail="   . rawurlencode($from);
    $postData .=  "&subject="     . rawurlencode('');
    $postData .=  "&body="        . rawurlencode($msg);
    $postData .=  "&fileContent=" . json_encode('');

    #echo $postData;
    echo "<br>Doing post request to send email...";
    $xmlstr = do_post_request(
      "http://mail.unifresh.com.au:3333/auto_sms_email.php", $postData);
    echo $xmlstr;
  }

  function getRunNums() {
    if( ! isset($_SESSION["runNums"])) {
      $sql = "select distinct cm.ADDITIONALFIELD_8 as runNum 
                from CUSTOMERMASTER cm where cm.customerstatus='Active' 
                and cm.ADDITIONALFIELD_8 is not null order by runNum";
      $dbh = dbConnect();
      $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
      dbClose($dbh);
      $runNums = ["(All)"];
      while($row = ibase_fetch_object($result)) {
        $runNums[] = $row->RUNNUM;
      }
      ibase_free_result($result);
      $runNums[] = "(NoRunNumber)";
      $_SESSION["runNums"] = $runNums;
    }
    foreach ($_SESSION["runNums"] as $runNum) {
      echo sprintf("<option %s>%s</option>", 
        $_POST["runNum"]==$runNum?"selected='selected'":"",
        $runNum);
    }
  }

  function getBillingGroups($selGroup) {
    if( ! isset($_SESSION["billingGroups"])) {
      $sql = "select distinct cm.ADDITIONALFIELD_47 as BILLGROUP 
                from CUSTOMERMASTER cm where cm.customerstatus='Active' 
                and cm.ADDITIONALFIELD_47 is not null
                order by BILLGROUP";
      $dbh = dbConnect();
      $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
      dbClose($dbh);
      $billGroups = ["(All)"];
      while($row = ibase_fetch_object($result)) {
        $billGroups[] = $row->BILLGROUP;
      }
      ibase_free_result($result);
      $billGroups[] = "(NoBillingGroup)";
      $_SESSION["billingGroups"] = $billGroups;
    }
    foreach ($_SESSION["billingGroups"] as $billG) {
      echo sprintf("<option %s>%s</option>",
        $selGroup==$billG?"selected='selected'":"",
        $billG);
    }
  }

  function searchSales($dtOrder, $runNum, $billGroup) {
    $dtOrderQuery = ((isset($dtOrder)) and !(empty($dtOrder)))? 
      "and sh.ORDERDATE ='$dtOrder'" : "";

    $runNumQuery = "";
    if($runNum == "(All)")
      $runNumQuery = "";
    else if ($runNum == "(NoRunNumber)")
      $runNumQuery = "and cm.ADDITIONALFIELD_8 is null";
    else
      $runNumQuery = "and cm.ADDITIONALFIELD_8 = '$runNum'";

    $billGroupQuery = "";
    if($billGroup == "(All)") 
      $billGroupQuery = "";
    else if($billGroup == "(NoBillingGroup)") 
      $billGroupQuery = "and cm.ADDITIONALFIELD_47 is null";
    else
      $billGroupQuery = "and cm.ADDITIONALFIELD_47 = '$billGroup'";
  
    #here are the firebird magic lines for displaying date in dd.mm.yyyy format
    #keeping here for reference
    #substring(100+extract(day from sh.orderdate) from 2 for 2)||'.'||
    #substring(100+extract(month from sh.orderdate) from 2 for 2)||'.'||
    #extract(year from sh.orderdate) as ORDERDATE,
    $sql = "select cm.CUSTOMER, cm.CUSTOMERMOBILE, sh.ORDERNUMBER, sh.ORDERDATE,
            cm.ADDITIONALFIELD_8 as RUNNUM, 
            cm.ADDITIONALFIELD_47 as BILLINGGROUP, sh.ORDERSTATUS, 
            sh.READINESSSTATUS, sh.ORDERCITY, sh.REQUIREDDATE, 
            sh.ORDERADDRESS1
            from CUSTOMERMASTER cm inner join SALESHEADER sh on 
            cm.CUSTOMER = sh.CUSTOMER and cm.CUSTOMERSTATUS='Active' 
            $dtOrderQuery $runNumQuery $billGroupQuery order by cm.CUSTOMER";

    $dbh = dbConnect();
    $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
    dbClose($dbh);

    $data = [];
    while($row = ibase_fetch_object($result)) {
      $data[] = [
                  $row->CUSTOMER,
                  $row->CUSTOMERMOBILE,
                  $row->ORDERNUMBER,
                  $row->ORDERDATE,
                  $row->RUNNUM,
                  $row->BILLINGGROUP,
                  $row->ORDERSTATUS,
                  $row->READINESSSTATUS,
                  $row->ORDERCITY,
                  $row->ORDERADDRESS1,
                ];
    }
    ibase_free_result($result);
    $_SESSION["headings"] = [ "Customer", "CustomerMobile","OrderNumber", 
                              "OrderDate", "RunNum",
                              "BillingGroup", "OrderStatus", "ReadinessStatus",
                              "OrderCity", "OrderAddress1",];
    $_SESSION["data"] = $data;
    $_SESSION["type"] = "searchSales";
    return $data;
  }

  function searchCusts($cust, $billGroup) {
    $custQuery = sprintf("and upper(cm.CUSTOMER) like UPPER('%%%s%%')", 
                  str_replace(" ","%",$cust));

    $billGroupQuery = "";
    if($billGroup == "(All)") 
      $billGroupQuery = "";
    else if($billGroup == "(NoBillingGroup)") 
      $billGroupQuery = "and cm.ADDITIONALFIELD_47 is null";
    else
      $billGroupQuery = "and cm.ADDITIONALFIELD_47 = '$billGroup'";

    $sql = "select cm.CUSTOMER, cm.CUSTOMERMOBILE, 
            cm.ADDITIONALFIELD_47 as BILLGROUP, 
            coalesce(cm.ADDITIONALFIELD_8, '(None)') AS RUNNUM,
            cm.CUSTOMERCITY, cm.CUSTOMERMOBILE
            from CUSTOMERMASTER cm
            where cm.CUSTOMERSTATUS='Active' 
            $custQuery $billGroupQuery";

    $dbh = dbConnect();
    $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
    dbClose($dbh);
    $data = [];
    while($row = ibase_fetch_object($result)) {
      $data[] = [
                  $row->CUSTOMER,
                  $row->CUSTOMERMOBILE,
                  $row->RUNNUM,
                  $row->BILLGROUP,
                  $row->CUSTOMERCITY,
                ];
    }
    ibase_free_result($result);

    $_SESSION["headings"] = [ "Customer", "CustomerMobile", "RunNum", "BillingGroup", "City",];
    $_SESSION["data"] = $data;
    $_SESSION["type"] = "searchCusts";
    return $data;
  }

  function createTable($result, $headings) {
    $table = '<table id="table" '.
              'class="display compact" style="width:100%">';
    $table .= "<thead>";

    $headings[] = "Delete";
    foreach($headings as $h) {
      $table .= "<th>$h</th>";
    }

    $table .= "</thead><tbody>";
    foreach($result as $row) {
      $table .= "<tr>";
      foreach($row as $col) {
        $table .= "<td>$col</td>";
      }
      $c = $row[0];
      $table .= sprintf("<td style=\"text-align:center;\">".
                  "<a href=\"javascript:formSubmit('$c');\">
                    <i class='fa fa-times text-danger' aria-hidden='true'>
                  </a>");
      $table .= "</tr>\n";
    }
    $table .= "</tbody></table>";
    return $table;
  }

  function showTemps() {
    $dbh = dbConnect();
    $sql = "select id, name from UF_SMS_TEMPLATES order by name";

    $result = ibase_query($dbh, $sql) or die (ibase_errmsg());
    while($row = ibase_fetch_object($result)) {
      echo sprintf("<option value='%s' %s>%s</option>", 
        $row->ID, 
        ($_POST["selTemplate"]==$row->ID)?"selected='selected'":"", 
        $row->NAME);
    }
    ibase_free_result($result);

    dbClose($dbh); 
  }

  function useTemp($Id) {
    $dbh = dbConnect();
    $sql = "select message from UF_SMS_TEMPLATES where id=".$Id;
    $result = ibase_query($dbh, $sql) or die (ibase_errmsg());
    $_POST["smsContent"] = stripslashes(ibase_fetch_assoc($result)["MESSAGE"]);
    ibase_free_result($result);
    dbClose($dbh);
  }

  function saveTemp($tname, $tmsg) {
    $tmsg = str_replace("'", "", $tmsg);
    $tname = str_replace("'", "", $tname);
    if(is_null($tmsg) || is_null($tname) || empty($tmsg) || empty($tname)) {
      flash("Invalid message or template name", "alert-danger");
      return;
    }
    $dbh = dbConnect();
    $sql = sprintf("update or insert into UF_SMS_TEMPLATES (id, name, message) ".
            "values((select coalesce(max(id),0)+1 from UF_SMS_TEMPLATES),".
            "'%s','%s') matching(name)",$tname, $tmsg);
    $result = ibase_query($dbh, $sql) or die (ibase_errmsg());
    dbClose($dbh);   
    flash("Template Saved","alert-success");
  } 

  function makeLog($msg, $data, $usr) {
    $dbh = dbConnect();

    #get unique id to be used in MSG and RCP tables
    $msgid = ibase_gen_id ("UF_GEN_SMS_LOG_MSGID", 1, $dbh);

    $sql = sprintf("insert into UF_SMS_LOG_MSG values
            ('%s','%s', timestamp 'now', '%s')", $msgid, $msg, $usr);
    #prepared query to be used repeatedly in loop
    $qh = ibase_prepare("insert into UF_SMS_LOG_RCP values(
            next value for UF_GEN_SMS_LOG_RCPID, ?, ?, ?)");

    #start a new transaction
    $tr = ibase_trans($dbh);

    #insert new row in msg table
    $log_res  =  ibase_query($dbh, $sql);

    #insert rows for each cust in rcp table
    foreach($data as $row) {
      $cust = $row[0];
      $mob = $row[1];
      $log_res = $log_res && ibase_execute($qh, $msgid, $cust, $mob);
      if(!$log_res) 
        break;
    }

    if(!$log_res) {
      ibase_rollback($tr);
      flash("Cannot log details", "alert-danger");
    }
    else {
      ibase_commit($tr);
    }
    
    ibase_free_query($qh);
    dbClose($dbh);
  }

  function downloadFile($file) {
    if (file_exists($file)) {
      header('Content-Description: File Transfer');
      header('Content-Type: application/octet-stream');
      header('Content-Disposition: attachment; filename="'.basename($file).'"');
      header('Expires: 0');
      header('Cache-Control: must-revalidate');
      header('Pragma: public');
      header('Content-Length: ' . filesize($file));
      readfile($file);
      unlink($file);
    }
  }

  function createReportCSV() {
    $file = "sms-report.csv";
    $fp = fopen($file,"w");
    $sql = "select cust,msg,mob,dt,usr from UF_SMS_LOG_MSG msg 
            inner join UF_SMS_LOG_RCP rcp 
            on msg.ID = rcp.MSGID order by msg.id desc, rcp.ID";
    $dbh = dbConnect();
    $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
    dbClose($dbh);

    $data = [];
    $data[] = ["Customer", "Msg", "Mob", "Dt", "Usr"];
    while($row = ibase_fetch_object($result)) {
      $data[] = [$row->CUST, $row->MSG, $row->MOB, $row->DT, $row->USR];
    }
    ibase_free_result($result);
    foreach($data as $row)
      fputcsv($fp, $row);
    fclose($fp);
    return $file;
  }

  function checkPin($pinCode) {
    if(! filter_var($pinCode, FILTER_VALIDATE_INT)) {
      unset($_SESSION["usr"]);
      flash("Wrong Pin", "alert-danger");
      return;
    }

    $sql = "select first 1 EMPLOYEE as USR from EMPLOYEEPINCODES 
            where PINCODE='$pinCode'";
    $dbh = dbConnect();
    $res = ibase_query($dbh, $sql);
    dbClose($dbh);
    $row = ibase_fetch_object($res);
    ibase_free_result($res);

    if(isset($row) and !empty($row->USR)) {
      $usr = $row->USR;
      $_SESSION["usr"] = $usr;
      flash("Logged in as $usr");
    }
    else {
      unset($_SESSION["usr"]);
      flash("Wrong Pin", "alert-danger");
    }    
  }

  function getSoonShadows($hours = "1") {
    $sql = "select
        CUSTOMER,
        CUSTOMERMOBILE,
        SHADOWNUMBER,
        cutoffday as \"CUTOFFDATE\",
        CUTOFFTIME,
        DISPATCHDAY as \"ORDERDATE\"
        from (
        select
        distinct
        ADDITIONALFIELD_1,
        ADDITIONALFIELD_47,
        CSUID,
        CUSTOMERSTATUS,
        CUSTOMERMOBILE,
        REGULARSCHED.CUSTOMER,
        CASE when ALTEREDSCHED.STATUS is null then
          CUTOFFDAY else NEWCUTOFFDAY END as \"CUTOFFDAY\",
        CASE when NEWCUTOFFTIME is null then
          CUTOFFTIME else NEWCUTOFFTIME END as \"CUTOFFTIME\",
        CASE when ALTEREDSCHED.STATUS is null then
          DISPATCHDAY else NEWDISPATCH END as \"DISPATCHDAY\",
        CASE when ALTEREDSCHED.STATUS is null then
          DELIVERYDAY else NEWDELIVERY END as \"DELIVERYDAY\",
        REGULARSCHED.DISPATCHDAY as \"ORIGINALDISPATCH\",
        ALTEREDSCHED.STATUS,
        SHADOWS.SHADOWNUMBER,
        SHADOWS.OUTPUTDATE,
        SHADOWSPROCESSED.SHADOWNUMBER as \"PROCESSNUMBER\",
        SHADOWSPROCESSED.PROCESSEDDATE,
        SHADOWS.ORDERTYPE,
        SIH.*
        from
        (
        select
        UF_CUST_SCHEDULES.CUSTOMER,
        CUSTOMERMASTER.CUSTOMERMOBILE,
        CASE when CSG.\"GROUP\" is null then CSGC.\"GROUP\"
          else CSG.\"GROUP\" END as \"CGROUP\",
        CASE when CUSTOMERMASTER.SHIPPINGMETHOD is null then 'UniFresh'
          else CUSTOMERMASTER.SHIPPINGMETHOD END as \"SMETHOD\",
        CUSTOMERMASTER.ADDITIONALFIELD_38 as \"STOREID\",
        CO.OUTPUTDATE as \"CUTOFFDAY\",
        UF_CUST_SCHEDULES.CUTOFFTIME,
        DI.OUTPUTDATE as \"DISPATCHDAY\",
        DE.OUTPUTDATE as \"DELIVERYDAY\",
        CASE when CSG.\"GROUP\" is null then CSGC.\"GROUP\" else
          CSG.\"GROUP\" END as \"SCHEDGROUP\",
        CUSTOMERMASTER.CUSTOMERSTATUS,
        CUSTOMERMASTER.ADDITIONALFIELD_1,
        customermaster.ADDITIONALFIELD_47,
        customermaster.SYSUNIQUEID as \"CSUID\"
        from
        CUSTOMERMASTER
        left outer join (select CUSTOMERGROUP, \"GROUP\"
          from CUSTOMERSCHEDULEGROUPS) CSGC on
        CASE when CUSTOMERMASTER.CUSTOMER not in
          (select distinct CUSTOMERGROUP from CUSTOMERSCHEDULEGROUPS) then
             CASE when CUSTOMERMASTER.ADDITIONALFIELD_1 not in
            (select distinct CUSTOMERGROUP from CUSTOMERSCHEDULEGROUPS) then
            'Standard' else CUSTOMERMASTER.ADDITIONALFIELD_1 END
        else CUSTOMERMASTER.CUSTOMER END = CSGC.CUSTOMERGROUP,
        UF_CUST_SCHEDULES
        left outer join (select CUSTOMERGROUP, \"GROUP\"
          from CUSTOMERSCHEDULEGROUPS) CSG on
          CSG.CUSTOMERGROUP = UF_CUST_SCHEDULES.CUSTOMER
        left outer join (select * from
          RETURN_DATESBETWEEN(current_date - 4, current_date + 6)) CO on
          CASE when UF_CUST_SCHEDULES.CUTOFFDAY = 7 then 0
          else UF_CUST_SCHEDULES.CUTOFFDAY END = EXTRACT(WEEKDAY
            from CO.OUTPUTDATE)
        left outer join (select * from
          RETURN_DATESBETWEEN(current_date - 4, current_date + 6)) DI on
          CASE when UF_CUST_SCHEDULES.DISPATCHDAY = 7 then 0
          else UF_CUST_SCHEDULES.DISPATCHDAY END = EXTRACT(WEEKDAY
          from DI.OUTPUTDATE)
        left outer join (select * from
        RETURN_DATESBETWEEN(current_date - 4, current_date + 8)) DE on
        CASE when UF_CUST_SCHEDULES.DELIVERYDAY = 7 then 0 else
        UF_CUST_SCHEDULES.DELIVERYDAY END = EXTRACT(WEEKDAY from DE.OUTPUTDATE)
        where
        CUSTOMERMASTER.CUSTOMER = UF_CUST_SCHEDULES.CUSTOMER and
        DI.OUTPUTDATE <= CO.OUTPUTDATE + 6 and
        DI.OUTPUTDATE >= CO.OUTPUTDATE and
        DE.OUTPUTDATE <= DI.OUTPUTDATE + 6 and
        DE.OUTPUTDATE >= DI.OUTPUTDATE
        order by
        SCHEDGROUP, SMETHOD, UF_CUST_SCHEDULES.CUSTOMER, CO.OUTPUTDATE
        ) REGULARSCHED
        left outer join
        (
        select UF_SCHEDULES.*,
        CUSTOMERSCHEDULEGROUPS.CUSTOMERGROUP,
        CUSTOMERSCHEDULEGROUPS.\"GROUP\"
        from
        UF_SCHEDULES,
        CUSTOMERSCHEDULEGROUPS
        where
        CUSTOMERSCHEDULEGROUPS.\"GROUP\" = UF_SCHEDULES.GROUPING
        ) ALTEREDSCHED on ALTEREDSCHED.\"GROUP\" = REGULARSCHED.SCHEDGROUP
        and ALTEREDSCHED.SHIPPINGMETHOD = REGULARSCHED.SMETHOD
        and ALTEREDSCHED.NORMALDISPATCH = REGULARSCHED.DISPATCHDAY
        left outer join
        (
        select salesshadowsheader.CUSTOMER, salesshadowsheader.SHADOWNUMBER,
        CO.OUTPUTDATE, salesshadowsheader.ORDERTYPE from
        salesshadowsheader
        left outer join (select * from
        RETURN_DATESBETWEEN(current_date - 4, current_date + 20)) CO
        on CASE when salesshadowsheader.ORDERDAY = 7 then 0 else
        salesshadowsheader.ORDERDAY END = EXTRACT(WEEKDAY from CO.OUTPUTDATE)
        where
        salesshadowsheader.SHADOWNUMBER in
        (select distinct SHADOWNUMBER from salesshadowslines) and
        (RECURRENCE = CO.OUTPUTDATE or RECURRENCE = '1984-08-30') and
        (salesshadowsheader.STATUS is null
        or salesshadowsheader.STATUS = 'Active')
        ) SHADOWS on SHADOWS.OUTPUTDATE = REGULARSCHED.DISPATCHDAY and
        SHADOWS.CUSTOMER = REGULARSCHED.CUSTOMER
        left outer join
        (
        select salesinvoiceheader.customer as \"SIHCUST\",
          salesinvoiceheader.invoicedate as \"SIHDATE\",
          salesinvoiceheader.INVOICENUMBER from salesinvoiceheader,
          salesinvoicegrouping, salesheader where
          (salesheader.additionalfield_3 IS NULL OR
           (salesheader.additionalfield_3 <> 'Addon Order'
          and salesheader.additionalfield_3 <> 'Shortage Replacement'))
          and salesinvoiceheader.invoicenumber =
          salesinvoicegrouping.invoicenumber and
          salesinvoicegrouping.ordernumber = salesheader.ordernumber
          and salesinvoiceheader.INVOICEDATE >= current_date - 2
          and salesinvoiceheader.INVOICEDATE <= current_date + 6
        ) SIH on CASE when ALTEREDSCHED.STATUS is null then DISPATCHDAY
        else NEWDISPATCH END = SIH.SIHDATE and
        SIH.SIHCUST = REGULARSCHED.CUSTOMER
        left outer join
        (
        select * from SALESSHADOWSPROCESSED where
        SALESSHADOWSPROCESSED.PROCESSEDDATE >= current_date - 3
        and SALESSHADOWSPROCESSED.PROCESSEDDATE <= current_date + 4
        ) SHADOWSPROCESSED on SHADOWSPROCESSED.PROCESSEDDATE + 1 =
        SHADOWS.OUTPUTDATE and SHADOWSPROCESSED.SHADOWNUMBER =
        SHADOWS.SHADOWNUMBER
        where
        (ALTEREDSCHED.STATUS is null or ALTEREDSCHED.STATUS = 'Enabled')
        )
        where
        DISPATCHDAY <> cast('2017-04-15' as Date) and
        DISPATCHDAY > current_date and
        INVOICENUMBER is null and
        ORDERTYPE='Shadow Order' and
        CUSTOMERSTATUS = 'Active' and
        shadownumber is not null and
        customermobile is not null and
        cutoffday = current_date and
        cutofftime > current_time and 
        (cutofftime < dateadd($hours hour to current_time) or 
          current_time > '23:00')
        order by
        cutoffday, cutofftime, CUSTOMER, DISPATCHDAY";

    #echo $sql;
    $dbh = dbConnect();
    $res = ibase_query($dbh, $sql);
    dbClose($dbh);

    if($res === false){
      echo "Error occurred";
      return 1;
    }

    $soonShadow = [];
    while($row = ibase_fetch_object($res)) {
      $soonShadow[] = [
                        "cust"            => $row->CUSTOMER,
                        "shadownum"       => $row->SHADOWNUMBER,
                        "cutoffdate"      => $row->CUTOFFDATE,
                        "userorderdate"   => (int)substr($row->ORDERDATE,8,2),
                        "cutofftime"      => $row->CUTOFFTIME,
                        "orderdate"       => $row->ORDERDATE,
                        "mob"             => [$row->CUSTOMERMOBILE],
                      ];
    }
    ibase_free_result($res);
    return $soonShadow;
  }

  function getTodaysLog() {
    $sql = "select shadownumber from uf_log_auto_sms
            where cutoffdate = current_date";
    $dbh = dbConnect();
    $res = ibase_query($dbh, $sql);
    dbClose($dbh);

    $todayLog = [];
    while($row = ibase_fetch_object($res)) {
      $todayLog[] = $row->SHADOWNUMBER;
    }
    ibase_free_result($res);
    return $todayLog;
  }

  function addAutoSMSLog($soonShadow, $msg, $status) {
    $half_sql = "insert into UF_LOG_AUTO_SMS values
      ('%s', '%s', '%s', '%s', '%s', '%s', '%s',current_timestamp, '%s', '%s')";

    $mobs = substr(implode(",", $soonShadow["mob"]), 0, 49);
    $outgoingID = $status["outgoingID"];
    $sentStatus = $status["status"];
  
    $sql = sprintf($half_sql,
      $soonShadow["cust"], $soonShadow["shadownum"],
      $soonShadow["cutoffdate"], $soonShadow["cutofftime"],
      $soonShadow["orderdate"], $mobs, $msg, $sentStatus, $outgoingID);

    echo "<br>$sql";
    $dbh = dbConnect();
    ibase_query($dbh, $sql) or die('Error: Unable to add log');
    dbClose($dbh);
  }

  function addOrd($number) {
    #https://stackoverflow.com/questions/3109978/display-numbers-with-
    #ordinal-suffix-in-php
    $ends = array('th','st','nd','rd','th','th','th','th','th','th');
    if ((($number % 100) >= 11) && (($number%100) <= 13))
        return $number. 'th';
    else
        return $number. $ends[$number % 10];
  }

  function sendAutoSMS() {
    $soonShadows = getSoonShadows("1" /*hours*/);
    echo "<pre>"; print_r($soonShadows); echo "</pre>";

    $todayLog = getTodaysLog();
    echo "<pre>"; print_r($todayLog); echo "</pre>";

    foreach($soonShadows as $thisSoonShadow) {
      if(! in_array($thisSoonShadow["shadownum"], $todayLog)) {

        $msg = sprintf("Dear %s, you have not placed ".
          "order for %s. Please avoid receiving shadow order by ".
          "placing one within one hour.".
          " Thanks.\nUniFresh",
          $thisSoonShadow["cust"], addOrd($thisSoonShadow["userorderdate"]));

        echo "<br>$msg<br>".strlen($msg);
        $status = sendMessage($msg, $thisSoonShadow["mob"]);
        echo "<br>Adding log:<br>";
        addAutoSMSLog($thisSoonShadow, $msg, $status);
      }
      else {
        echo "<br>Found $thisSoonShadow[shadownum] for $thisSoonShadow[cust]";
      }
    }
  }

  function getProcessingSMS() {
    $sql = "select * from UF_LOG_AUTO_SMS where cutoffdate = CURRENT_DATE 
           and (msgstatus = 'Processing' or 
                msgstatus = 'sent' or
                msgstatus is null or 
                msgstatus='')";
    $dbh = dbConnect();
    $res = ibase_query($dbh, $sql);

    $arrOutID = [];
    while($row = ibase_fetch_object($res)) {
      $arrOutID[] = $row->OUTGOINGID;
    }
    ibase_free_result($res);
    dbClose($dbh);
    return $arrOutID;
  }

  function updateSMSStatus() {
    $strSqlUp = "update UF_LOG_AUTO_SMS 
            set MSGSTATUS='%s' where OUTGOINGID='%s'";

    $arrOutID = getProcessingSMS();
    foreach($arrOutID as $outID){
      $newStatus = getMessageStatus($outID);
      
      if($newStatus == 'Processing' or
         $newStatus == 'sent') {
        echo "Status is still '$newStatus'. Skipping";
        continue;
      }

      $sqlFull = sprintf($strSqlUp, $newStatus, $outID);
      echo "<br>$sqlFull";
      $dbh = dbConnect();
      ibase_query($dbh, $sqlFull);
      ibase_commit($dbh);
      dbClose($dbh);
    }
  }

  function createReportContent() {
    $sql = "select * from UF_LOG_AUTO_SMS
      where cast(msgts as date) = current_date-1 order by msgts";
    $dbh = dbConnect();
    $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
    dbClose($dbh);

    $data = [];
    while($row = ibase_fetch_object($result)) {
      $data[] = [ $row->CUSTOMER, $row->CUTOFFTIME, 
                  $row->MOBILE, $row->MSGSTATUS, 
                  $row->OUTGOINGID, $row->MSGTS];
    }
    echo "Number of records: ". (count($data)-1);
    ibase_free_result($result);
    return $data;
  }

  function createHTMLTable($data) {
    $table = "<!DOCTYPE html>
              <html>
                <head>
                  <style>
                    table {
                        font-family: arial, sans-serif;
                        border-collapse: collapse;
                        width: 100%;
                    }

                    td, th {
                        border: 1px solid #dddddd;
                        text-align: left;
                        padding: 8px;
                    }

                    tr:nth-child(even) {
                        background-color: #dddddd;
                    }
                  </style>
                </head>
                <body>
                  <h3>Auto SMS sent yesterday:</h3>
                  <div style=\"overflow-x:auto;\">
                    <table>
                      <tr>
                        <th>Customer</th>
                        <th>CutOffTime</th>
                        <th>Mobile</th>
                        <th>MsgStatus</th>
                        <th>OutgoingID</th>
                        <th>MsgTimeStamp</th>
                      </tr>";
    foreach($data as $row) {
      $table .= "<tr>";
      $table .= "<td>$row[0]</td>";
      $table .= "<td>$row[1]</td>";
      $table .= "<td>$row[2]</td>";
      $table .= "<td>$row[3]</td>";
      $table .= "<td>$row[4]</td>";
      $table .= "<td>$row[5]</td>";
      $table .= "</tr>";
    }
    $table .= "
                    </table>
                  </div>
                </body>
              </html>";
    return $table;
  }

  function do_post_request($url, $postdata = "") {
    $params = ['http' => ['method' => 'POST',
                          'content' => $postdata]];

    $ctx = stream_context_create($params);

    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp) {
      throw new Exception("Problem with $url, $php_errormsg");
    }

    $response = @stream_get_contents($fp);
    if ($response === false) {
      throw new Exception("Problem reading data from ${url}, $php_errormsg");
    }
    return $response;
  }

  function sendAutoReport() {
    $from = "technology@unifresh.com.au";
    $to = "admin@unifresh.com.au";
    $subject = "Auto SMS Log";
    $reportContent = createReportContent();
    $body = createHTMLTable($reportContent);
    $postData  =  "";
    $postData .=  "toEmail="      . rawurlencode($to);
    $postData .=  "&fromEmail="   . rawurlencode($from);
    $postData .=  "&subject="     . rawurlencode($subject);
    $postData .=  "&body="        . rawurlencode($body);
    $postData .=  "&fileContent=" . json_encode('');

    #echo $postData;
    echo "<br>Doing post request to send email...";
    $xmlstr = do_post_request(
      "http://mail.unifresh.com.au:3333/auto_sms_email.php", $postData);
    echo $xmlstr;
  }

  function doTest() {
    getMessageStatus("3202668142");
    getMessageStatus("3202668161");
    #$jsonStr = '{"messages":[{"id":6594742834637759,"outgoing_id":3198902972,"origin":"61407580106","destination":"61481715080","message":"In association football, the FIFA World Cup concludes with France (team pictured) defeating Croatia in the final.","status":"sent","dateTime":"2018-07-25 10:08:26 +1000"}]} ';

    #$jsonStr = sendMessage($str, ["0481715080"]);   
    #$json= json_decode($jsonStr, true);
    #echo "<pre>";
    #print_r($json);
    #echo "</pre>";
    #echo "status: ".$json["messages"][0]["status"];
    #$msgRet = sendMessage("crazy fox jumped over the lazy dog and then slept for two hours.", ["0481715080"]);
    #print_r($msgRet);
  }

  function start() {
    if($_SERVER["REQUEST_METHOD"] == "POST") {
      if(isset($_POST["btnSubmit"])) {
        switch($_POST["btnSubmit"]) {
          case "saveTemp":
            saveTemp($_POST["tempName"], 
                     $_POST["smsContent"]);
            break;
          case "useTemp":
            useTemp(addslashes($_POST["selTemplate"]));
            break;
          case "btnSendMsg":
            if(isset($_SESSION["data"]) && !empty($_POST["smsContent"])) {
              $msgRet = sendMessage($_POST["smsContent"], 
                  array_column($_SESSION["data"],1));

              if($msgRet["status"] == "sent") {
                flash("Messages Sent","alert-success");
                makeLog($_POST["smsContent"], $_SESSION["data"], 
                  $_SESSION["usr"]);
              }
              else {
                flash("Unable to send message","alert-danger");
              }

            }
            break;
          case "btnReport":
            $file = createReportCSV();
            downloadFile($file);
            break;
          case "getPin":
            checkPin($_POST["pinCode"]);
            break;
        }
      }
      else if (isset($_POST["delCustName"])) {
        $cname = $_POST["delCustName"];
        $indx = array_search($cname, array_column($_SESSION["data"], 0), true);
        array_splice($_SESSION["data"], $indx, 1);
      }
    }
    else if($_SERVER["REQUEST_METHOD"] == "GET") {
      session_unset();
      session_destroy();
      if(isset($_GET["autosms"]) and $_GET["autosms"]=="yes") {
        sendAutoSMS();
        updateSMSStatus();
        exit;
      }
      if(isset($_GET["autoreport"]) and $_GET["autoreport"]=="yes") {
        sendAutoReport();
        exit;
      }
      if(isset($_GET["test"]) and $_GET["test"]=="yes") {
        doTest();
        exit;
      }
    }
  }

  start();
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity=
      "sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" 
      crossorigin="anonymous"></script>
    <script src=
     "https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js" 
      integrity=
      "sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49" 
      crossorigin="anonymous"></script>
    <link rel="stylesheet" href=
      "https://stackpath.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css"
       integrity=
      "sha384-WskhaSGFgHYWDcbwN70/dfYBj47jz9qbsMId/iRN3ewGhXQFZCSftd1LZCfmhktB" 
      crossorigin="anonymous">
    <script src=
      "https://stackpath.bootstrapcdn.com/bootstrap/4.1.1/js/bootstrap.min.js" 
      integrity=
      "sha384-smHYKdLADwkXOn1EmN1qk/HfnUcbVRZyYmZ4qpPea6sjB/pTJ0euyQp0Mk8ck+5T" 
      crossorigin="anonymous"></script>
    <script type="text/javascript" charset="utf8" 
      src="https://cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href=
      "https://use.fontawesome.com/releases/v5.1.0/css/all.css" integrity=
      "sha384-lKuwvrZot6UHsBSfcMvOkWwlCMgc0TaWr+30HWe3a4ltaBwTZhyTEggF5tJv8tbt" 
      crossorigin="anonymous">
    <link rel="stylesheet" type="text/css" href=
      "https://cdn.datatables.net/1.10.19/css/jquery.dataTables.css">
    <style>
      #logo {
        max-width: 30%;
        height: auto;
      }
      #subhead {
        vertical-align: bottom;
      }
    </style>
  </head>
  <body>
    <div class="container">
      <h1 class="mt-4 mb-5">
        <img src="copy_color_logo.jpg" alt="logo" id="logo"/>
        <small class="text-muted" id="subhead">
          SMS Sender
        </small>
      </h1>
        
      <form method="post" action="<?= $_SERVER["PHP_SELF"] ?>">
        <?php if($_SERVER["REQUEST_METHOD"] == "GET" 
                or !isset($_SESSION["usr"])) { ?>
        <div class="row">
          <div class="col-sm-2">
            <input type="password" class="form-control form-control-sm"
              name="pinCode" id="pinCode" placeholder="Enter Pin Code">
          </div>
          <div class="col-sm-2">
            <button type="submit" class="btn btn-info align-bottom" 
              id="getPin" name="btnSubmit" value="getPin">
              Submit
            </button>
          </div>
        </div>
        <?php } else if ($_SERVER["REQUEST_METHOD"] == "POST" 
                and isset($_SESSION["usr"])) { ?>
        <input type="hidden" name="delCustName" value="-1">
        <p>
          <button class="btn btn-primary" type="button" data-toggle="collapse" 
            data-target="#collapseSales" aria-expanded="false" 
            aria-controls="collapseSales" id="btnSales">
              Search by Sales <i class="far fa-credit-card"></i>
          </button>
          <button class="btn btn-primary" type="button" data-toggle="collapse" 
            data-target="#collapseCusts" aria-expanded="false" 
            aria-controls="collapseCusts" id="btnCusts">
              Search by Customers <i class="fas fa-address-card"></i>
          </button>
          <button class="btn btn-light btn-sm" type="submit" 
            name="btnSubmit" value="btnReport" id="btnReport">
            Report <i class="far fa-file-excel" aria-hidden="true"></i>
          </button>
        </p>
        <div class="collapse" id="collapseSales">
          <div class="card card-body mb-3">
            <h5 class="card-title text-success">Search by Sales</h5>
            <div class="row">
              <div class="col-sm-2">
                <label for="dtOrder">Order Date: </label>
                <input type="text" class="form-control form-control-sm"
                  name="dtOrder" id="dtOrder" placeholder="dd.mm.yyyy"
                  value="<?= $_POST["dtOrder"] ?? date("d.m.Y") ?>">
              </div>
              <div class="col-sm-2">
                <label for="runNum">Run Number</label>
                <select class="form-control form-control-sm"
                  name="runNum" id="runNum">
                  <?php getRunNums() ?>
                </select>
              </div>
              <div class="col-sm-5">
                <label for="billGroupS">Billing Group</label>
                <select class="form-control form-control-sm"
                  name="billGroupS" id="billGroupS">
                  <?php getBillingGroups($_POST["billGroupS"]??"") ?>
                </select>
              </div>
            </div>
            <div class="row mt-3">
              <div class="col-sm-3">
                <button type="submit" class="btn btn-info align-bottom" 
                  id="searchSales" name="btnSubmit" value="searchSales">
                  Search by Sales
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="collapse" id="collapseCusts">
          <div class="card card-body mb-3">
            <h5 class="card-title text-success">Search by Customers</h5>
            <div class="row">
              <div class="col-sm-4">
                <label for="dtOrder">Customer Search </label>
                <input type="text" class="form-control form-control-sm"
                  name="custName" id="custName" placeholder="Customer Search"
                  value="<?= $_POST["custName"]??"" ?>">
              </div>
              <div class="col-sm-5">
                <label for="billGroupC">Billing Group</label>
                <select class="form-control form-control-sm"
                  name="billGroupC" id="billGroupC">
                  <?php getBillingGroups($_POST["billGroupC"]??"") ?>
                </select>
              </div>
            </div>
            <div class="row mt-3">
              <div class="col-sm-3">
                <button type="submit" class="btn btn-info align-bottom" 
                  id="searchCusts" name="btnSubmit" value="searchCusts">
                  Search by Customers
                </button>
              </div>
            </div>
          </div>
        </div>
        <?php 
          if(isset($_POST["btnSubmit"]) && $_POST["btnSubmit"] == "searchSales")
            searchSales($_POST["dtOrder"], $_POST["runNum"], $_POST["billGroupS"]);
          else if(isset($_POST["btnSubmit"]) && $_POST["btnSubmit"] == "searchCusts")
            searchCusts($_POST["custName"], $_POST["billGroupC"]);
          
          if(isset($_SESSION["data"]))
            echo createTable($_SESSION["data"], $_SESSION["headings"]);
        ?>
        <div class="row mt-5">
          <div class="col-sm-2">
            <label for="selTemplate">Select Template</label>
            <select class="form-control form-control-sm"
              name="selTemplate" id="selTemplate">
              <?php showTemps();  ?>
            </select>
            <button type="submit" class="btn btn-info align-bottom mt-2" 
              name="btnSubmit" value="useTemp">
              Load <i class="fas fa-angle-double-right"></i>
            </button>
          </div>
          <div class="col-sm-8">
            <label for="smsContent">Message</label>
            <textarea class="form-control form-control-sm"
              rows="5" name="smsContent" 
              id="smsContent"><?=$_POST["smsContent"]??""?></textarea>
            <button type="submit" class="btn btn-danger align-bottom mt-2 mb-3" 
              id="btnSendMsg" name="btnSubmit" value="btnSendMsg" 
              onclick="return confirm('Sure to send messages?');">
              Send Message <i class="fas fa-envelope"></i>
            </button>
          </div>
          <div class="col-sm-2">
            <label for="tempName">Save Template</label>
            <input type="text" class="form-control form-control-sm"
              name="tempName" id="tempName" placeholder="Template Name">
            <button type="submit" class="btn btn-info align-bottom mt-2" 
              name="btnSubmit" value="saveTemp">
              Save <i class="fas fa-save"></i>
            </button>
          </div>
        </div>
        <?php } ?>
      </form>
    </div>
    <script>
      $(function() {
        $("#btnSales").click(function(){
          $("#collapseCusts").removeClass("show");
        });
        $("#btnCusts").click(function(){
          $("#collapseSales").removeClass("show");
        });
        $('#table').DataTable({
          "scrollX":        true,
          "scrollY":        "400px",
          "scrollCollapse": true,
          "paging":         false,
          "columnDefs": [{
                          "targets":[1],
                          "visible": false
                        }]
        });
      });
      function formSubmit(cust) {
        document.forms[0].delCustName.value = cust;
        document.forms[0].submit();
      }
    </script>
  </body>
</html>
