<?php
//
// Description
// -----------
// This function is a generic wrapper that can call any method located in a cron folder.
// It takes an array as an argument, and withing that must
// contain api_key, and method.
//
// Info
// ----
// status:      beta
//
// Arguments
// ---------
//
function ciniki_cron_execCronMethod($ciniki, $cronjob) {

    list($package, $module, $function) = preg_split('/\./', $cronjob['method']);

    //
    // Check the tnid has the cron module enabled
    //
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbQuote');
    $strsql = "SELECT ciniki_tenants.id FROM ciniki_tenants, ciniki_tenant_modules "
        . "WHERE ciniki_tenants.id = '" . ciniki_core_dbQuote($ciniki, $cronjob['tnid']) . "' "
        . "AND ciniki_tenants.status = 1 "
        . "AND ciniki_tenants.id = ciniki_tenant_modules.tnid "
        . "AND ciniki_tenant_modules.package = 'ciniki' "
        . "AND ciniki_tenant_modules.module = 'cron' "
        . "AND ciniki_tenant_modules.status = 1 "
        . "";
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbHashQuery');
    $rc = ciniki_core_dbHashQuery($ciniki, $strsql, 'ciniki.tenants', 'tenant');
    if( $rc['stat'] != 'ok' ) {
        return $rc;
    }
    if( !isset($rc['tenant']) || !isset($rc['tenant']['id']) || $rc['tenant']['id'] != $cronjob['tnid'] ) {
        return array('stat'=>'fail', 'err'=>array('code'=>'ciniki.cron.1', 'msg'=>'Unable to validate tenant'));
    }

    //
    // Check the module requested is enabled
    //
    $strsql = "SELECT ciniki_tenants.id FROM ciniki_tenants, ciniki_tenant_modules "
        . "WHERE ciniki_tenants.id = '" . ciniki_core_dbQuote($ciniki, $cronjob['tnid']) . "' "
        . "AND ciniki_tenants.status = 1 "
        . "AND ciniki_tenants.id = ciniki_tenant_modules.tnid "
        . "AND ciniki_tenant_modules.package = '" . ciniki_core_dbQuote($ciniki, $package) . "' "
        . "AND ciniki_tenant_modules.module = '" . ciniki_core_dbQuote($ciniki, $module) . "' "
        . "AND ciniki_tenant_modules.status = 1 "
        . "";
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbHashQuery');
    $rc = ciniki_core_dbHashQuery($ciniki, $strsql, 'ciniki.tenants', 'tenant');
    if( $rc['stat'] != 'ok' ) {
        return $rc;
    }
    if( !isset($rc['tenant']) || !isset($rc['tenant']['id']) || $rc['tenant']['id'] != $cronjob['tnid'] ) {
        return array('stat'=>'fail', 'err'=>array('code'=>'ciniki.cron.2', 'msg'=>'Unable to validate tenant'));
    }

    //
    // Start a transaction
    //
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbTransactionStart');
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbTransactionRollback');
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbTransactionCommit');
    $rc = ciniki_core_dbTransactionStart($ciniki, 'ciniki.cron');
    if( $rc['stat'] != 'ok' ) { 
        return $rc;
    }   

    //
    // Update the status to running, if not already
    // Verify the next_exec is still < UTC_TIMESTAMP (locking check)
    //
    $strsql = "UPDATE ciniki_cron "
        . "SET status = 2 "
        . "WHERE id = '" . ciniki_core_dbQuote($ciniki, $cronjob['id']) . "' "
        . "AND status = 1 "
        . "AND next_exec < UTC_TIMESTAMP() "
        . "";
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbUpdate');
    $rc = ciniki_core_dbUpdate($ciniki, $strsql, 'ciniki.cron');
    if( $rc['stat'] != 'ok' ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return $rc;
    }
    if( $rc['num_affected_rows'] != 1 ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return array('stat'=>'fail', 'err'=>array('code'=>'ciniki.cron.3', 'msg'=>'Unable to lock cron'));
    }

    //
    // Parse the method, and the function name
    //
    $method_filename = $ciniki['config']['core']['root_dir'] . "/$package-mods/$module/cron/$function.php";
    $method_function = preg_replace('/\./', '_', $cronjob['method']);

    //
    // Check if the method exists, after we check for authentication,
    // because we don't want people to be able to figure out valid
    // function calls by probing.
    //
    if( $method_filename == '' || $method_function == '' || !file_exists($method_filename) ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return array('stat'=>'fail', 'err'=>array('code'=>'ciniki.cron.4', 'msg'=>'Method does not exist'));
    }

    //
    // Include the method function
    //
    require_once($method_filename);

    if( !is_callable($method_function) ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return array('stat'=>'fail', 'err'=>array('code'=>'ciniki.cron.5', 'msg'=>'Method does not exist'));
    }

    $method_ciniki = $ciniki;
    
    $cronjob['args'] = unserialize($cronjob['serialized_args']);
    $method_rc = $method_function($ciniki, $cronjob);

    //
    // Save the result in the ciniki_cron_logs table
    //
    $strsql = "INSERT INTO ciniki_cron_logs (cron_id, status, result, date_added) "
        . "VALUES ('" . ciniki_core_dbQuote($ciniki, $cronjob['id']) . "' "
        . ", '" . ciniki_core_dbQuote($ciniki, $method_rc['stat']) . "' "
        . ", '" . ciniki_core_dbQuote($ciniki, serialize($method_rc)) . "' "
        . ", UTC_TIMESTAMP()) ";
    ciniki_core_loadMethod($ciniki, 'ciniki', 'core', 'private', 'dbInsert');
    $rc = ciniki_core_dbInsert($ciniki, $strsql, 'ciniki.cron');
    if( $rc['stat'] != 'ok' ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return $rc;
    }

    //
    // Calculate the next scheduled cron for this job
    //
    ciniki_core_loadMethod($ciniki, 'ciniki', 'cron', 'private', 'calcNextExec');
    $rc = ciniki_cron_calcNextExec($ciniki, $cronjob['h'], $cronjob['m'], $cronjob['dom'], $cronjob['mon'], $cronjob['dow']);
    if( $rc['stat'] != 'ok' ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return $rc;
    }
    if( !isset($rc['next']) || !isset($rc['next']['utc']) || $rc['next']['utc'] == '' ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return array('stat'=>'fail', 'err'=>array('code'=>'ciniki.cron.6', 'msg'=>'Unable to calculate next event'));
    }
    $next_exec_utc = $rc['next']['utc'];
    
    //
    // Unlock and schedule the next transaction
    //
    $strsql = "UPDATE ciniki_cron "
        . "SET status = 1 "
        . ", last_status = '" . ciniki_core_dbQuote($ciniki, $method_rc['stat']) . "' "
        . ", last_exec = UTC_TIMESTAMP() "
        . ", next_exec = '" . ciniki_core_dbQuote($ciniki, $next_exec_utc) . "' "
        . "WHERE id = '" . ciniki_core_dbQuote($ciniki, $cronjob['id']) . "' "
        . "AND status = 2 "
        . "";
    $rc = ciniki_core_dbUpdate($ciniki, $strsql, 'ciniki.cron');
    if( $rc['stat'] != 'ok' ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return $rc;
    }
    if( $rc['num_affected_rows'] != 1 ) {
        ciniki_core_dbTransactionRollback($ciniki, 'ciniki.cron');
        return array('stat'=>'fail', 'err'=>array('code'=>'ciniki.cron.7', 'msg'=>'Unable to lock cron'));
    }

    //
    // Commit any transactions
    //
    $rc = ciniki_core_dbTransactionCommit($ciniki, 'ciniki.cron');
    if( $rc['stat'] != 'ok' ) {
        return $rc;
    }

    return array('stat'=>'ok');
}
