<?php
  #session_start();
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
      echo "<option>$runNum</option>";
    }
  }

  function getBillingGroups() {
    if( ! isset($_SESSION["billingGroups"])) {
      $sql = "select distinct cm.ADDITIONALFIELD_47 as billGroup 
                from CUSTOMERMASTER cm where cm.customerstatus='Active' 
                order by billGroup";
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
      echo "<option>$billG</option>";
    }
  }
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
     "https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js"      integrity=
      "sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49"       crossorigin="anonymous"></script>
    <link rel="stylesheet" href=
      "https://stackpath.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css"      integrity=
      "sha384-WskhaSGFgHYWDcbwN70/dfYBj47jz9qbsMId/iRN3ewGhXQFZCSftd1LZCfmhktB" 
      crossorigin="anonymous">
    <script src=
      "https://stackpath.bootstrapcdn.com/bootstrap/4.1.1/js/bootstrap.min.js" 
      integrity=
      "sha384-smHYKdLADwkXOn1EmN1qk/HfnUcbVRZyYmZ4qpPea6sjB/pTJ0euyQp0Mk8ck+5T" 
      crossorigin="anonymous"></script>

    <link rel="stylesheet" href=
      "https://use.fontawesome.com/releases/v5.1.0/css/all.css" integrity=
      "sha384-lKuwvrZot6UHsBSfcMvOkWwlCMgc0TaWr+30HWe3a4ltaBwTZhyTEggF5tJv8tbt"       crossorigin="anonymous">
  </head>
  <body>
    <div class="container">
      <h1>
        Unifresh SMS Sender <i class="fas fa-leaf text-success mb-5 mt-4"></i>
      </h1>
      <form method="post" action=<?= $_SERVER["PHP_SELF"] ?>>
        <p>
          <button class="btn btn-primary" type="button" data-toggle="collapse" 
            data-target="#collapseSales" aria-expanded="false" 
            aria-controls="collapseSales" id="btnSales">
              SMS by Sales
          </button>
          <button class="btn btn-primary" type="button" data-toggle="collapse" 
            data-target="#collapseCusts" aria-expanded="false" 
            aria-controls="collapseCusts" id="btnCusts">
              SMS by Customers
          </button>
        </p>
        <div class="collapse" id="collapseSales">
          <div class="card card-body">
            <h5 class="card-title text-success">Search by Sales</h5>
            <div class="row">
              <div class="col-sm-2">
                <label for="dtOrder">Order Date: </label>
                <input type="text" class="form-control form-control-sm"
                  name="dtOrder" id="dtOrder" placeholder="dd-mm-yyyy"
                  value=<?= $_POST["dtOrder"] ?? date("d-m-Y") ?>>
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
                  <?php getBillingGroups() ?>
                </select>
              </div>
              <div class="col-sm-3">
                <label for="searchSales">Start Search</label>
                <button type="submit" class="btn btn-info align-bottom" 
                  name="searchSales" value="searchSales">
                  Search by Sales
                </button>
              </div>
            </div>
          </div>
        </div>
        <div class="collapse" id="collapseCusts">
          <div class="card card-body">
            <h5 class="card-title text-success">Search by Customers</h5>
            <div class="row">
              <div class="col-sm-4">
                <label for="dtOrder">Customer Search </label>
                <input type="text" class="form-control form-control-sm"
                  name="custName" id="custName" placeholder="Customer Search">
              </div>
              <div class="col-sm-5">
                <label for="billGroupC">Billing Group</label>
                <select class="form-control form-control-sm"
                  name="billGroupC" id="billGroupC">
                  <option>Billing Group One</option>
                  <option>Billing Group Two</option>
                </select>
              </div>
              <div class="col-sm-3">
                <label for="searchCusts">Start Search</label>
                <button type="submit" class="btn btn-info align-bottom" 
                  name="searchCusts" value="searchCusts">
                  Search by Customers
                </button>
              </div>
            </div>
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
      });
    </script>
  </body>
</html>
