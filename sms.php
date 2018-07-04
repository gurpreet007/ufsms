<?php
  session_start();
  function dbConnect() {
    $host   = 'localhost:';
    $dbname = '/var/www/html/ufsms/kunwardb.fdb';
    $dbuser = 'sysdba';
    $dbpass = 'masterkey';

    $dbh = ibase_connect($host.$dbname, $dbuser, $dbpass) or 
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
      <button type='button' class='close' data-dismiss='alert' aria-label='Close'>
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

    $key = "955ef5eeb4e85dc312ced7193e4cea67";
    $secret = "93b20c19134c8efedcc6504f369b1c21";

    $concat_str = sprintf("%s\n%s\n%s\n%s\n%s\n%s\n%s\n",
      $ts, $nonce, $method, $action, $http_host, $http_port, $opt_data);

    $sig = hash_hmac("sha256", $concat_str, $secret, true);

    $hash = base64_encode($sig);

    $mac = sprintf('MAC id="%s", ts="%s", nonce="%s", mac="%s"', 
      $key, $ts, $nonce, $hash);

    return $mac;
  }

  function sendMessage($msg, $mobs) {
    $action = "/v2/sms/";
    $crl = curl_init("https://api.smsglobal.com".$action);
    $header = [ 'Content-type: application/json',
                'Accept: application/json',
                'Authorization: '. getAuthHTTPHeader("POST", $action)];
    curl_setopt($crl, CURLOPT_HTTPHEADER, $header);

    $data = [ 
              'destinations'  => $mobs,
              'origin'        => 'test', 
              'message'       => $msg,
              'sharedPool'    =>  '',
            ];
    curl_setopt($crl, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($crl, CURLOPT_POST, true);
    curl_setopt($crl, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($crl, CURLOPT_RETURNTRANSFER, true);
    $rest = curl_exec($crl) or die(curl_error($crl));
    curl_close($crl);
    if(strpos($rest, "authentication failed")) 
      flash($rest,"alert-danger");
    else 
      flash(count($mobs)." Messages Sent","alert-success");
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
                order by BILLGROUP";
      $dbh = dbConnect();
      $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
      dbClose($dbh);
      $billGroups = ["(All)"];
      while($row = ibase_fetch_object($result)) {
        $billGroups[] = $row->BILLGROUP;
      }
      ibase_free_result($result);
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

    $billGroupQuery = $billGroup=="(All)" ? 
      "" : "and cm.ADDITIONALFIELD_47 = '$billGroup'";
  
    #here are the firebird magic lines for displaying date in dd.mm.yyyy format
    #keeping here for reference
    #substring(100+extract(day from sh.orderdate) from 2 for 2)||'.'||
    #substring(100+extract(month from sh.orderdate) from 2 for 2)||'.'||
    #extract(year from sh.orderdate) as ORDERDATE,
    $sql = "select cm.CUSTOMER, sh.ORDERNUMBER, sh.ORDERDATE,
            cm.ADDITIONALFIELD_8 as RUNNUM, 
            cm.ADDITIONALFIELD_47 as BILLINGGROUP, sh.ORDERSTATUS, 
            sh.READINESSSTATUS, sh.ORDERCITY, sh.REQUIREDDATE, 
            sh.ORDERADDRESS1, cm.CUSTOMERMOBILE
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
    $_SESSION["headings"] = [ "Customer", "OrderNumber", "OrderDate", "RunNum",
                              "BillingGroup", "OrderStatus", "ReadinessStatus",
                              "OrderCity", "OrderAddress1",];
    $_SESSION["data"] = $data;
    $_SESSION["type"] = "searchSales";
    return $data;
  }

  function searchCusts($cust, $billGroup) {
    $custQuery = sprintf("and upper(cm.CUSTOMER) like UPPER('%%%s%%')", 
                  str_replace(" ","%",$cust));

    $billGroupQuery = $billGroup=="(All)" ? 
      "" : "and cm.ADDITIONALFIELD_47 = '$billGroup'";

    $sql = "select cm.CUSTOMER, cm.ADDITIONALFIELD_47 as BILLGROUP, 
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
                  $row->RUNNUM,
                  $row->BILLGROUP,
                  $row->CUSTOMERCITY,
                ];
    }
    ibase_free_result($result);

    $_SESSION["headings"] = [ "Customer", "RunNum", "BillingGroup", "City",];
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
    $sql = "select id, name from templates order by name";

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
    $sql = "select message from templates where id=".$Id;
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
    $sql = sprintf("update or insert into templates (id, name, message) ".
            "values((select coalesce(max(id),0)+1 from templates),".
            "'%s','%s') matching(name)",$tname, $tmsg);
    $result = ibase_query($dbh, $sql) or die (ibase_errmsg());
    dbClose($dbh);   
    flash("Template Saved","alert-success");
  } 

  function getMobs($rows) {
    $mobs = [];
    $prefix = "select CUSTOMERMASTER.CUSTOMERMOBILE from CUSTOMERMASTER ".
              "inner join ( select 'xxxxxxx' as CUSTOMER from RDB\$DATABASE ";
    $suffix = ") as x on CUSTOMERMASTER.CUSTOMER = x.CUSTOMER";
    $cust = array_column($rows, 0);
    $custLines = array_map(function($v){
      return "union all select '${v}' from RDB\$DATABASE"; }, $cust);
    $sql = $prefix.implode(" ",$custLines).$suffix;
    
    $dbh = dbConnect();
    $result = ibase_query($dbh, $sql) or die(ibase_errmsg());
    dbClose($dbh);
    while($row = ibase_fetch_object($result)) {
      $mobs[] = $row->CUSTOMERMOBILE;
    }
    ibase_free_result($result);
    $mobs = array_unique($mobs);
    return $mobs;
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
            if(isset($_SESSION["data"]) 
                && !empty($_POST["smsContent"])) {
              $mobs = getMobs($_SESSION["data"]);
              sendMessage($_POST["smsContent"], $mobs);
            }
            break;
        }
      }
      else if (isset($_POST["delCustName"])) {
        $cname = $_POST["delCustName"];
        $indx = array_search($cname, array_column($_SESSION["data"], 0), true);
        array_splice($_SESSION["data"], $indx, 1);
      }
    }
    else {
      session_unset();
      session_destroy();
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
        <input type="hidden" name="delCustName" value="-1">
        <p>
          <button class="btn btn-primary" type="button" data-toggle="collapse" 
            data-target="#collapseSales" aria-expanded="false" 
            aria-controls="collapseSales" id="btnSales">
              Search by Sales <i class="fas fa-table"></i>
          </button>
          <button class="btn btn-primary" type="button" data-toggle="collapse" 
            data-target="#collapseCusts" aria-expanded="false" 
            aria-controls="collapseCusts" id="btnCusts">
              Search by Customers <i class="fas fa-address-card"></i>

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
              id="smsContent"><?= $_POST["smsContent"]??"" ?></textarea>
            <button type="submit" class="btn btn-danger align-bottom mt-2 mb-3" 
              id="btnSendMsg" name="btnSubmit" value="btnSendMsg" onclick="return confirm('Sure to send messages?');">
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
          "paging":         false
        });
      });
      function formSubmit(cust) {
        document.forms[0].delCustName.value = cust;
        document.forms[0].submit();
      }
    </script>
  </body>
</html>
