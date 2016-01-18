<?php
/*=========================================================================

  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) 2002 Kitware, Inc.  All rights reserved.
  See Copyright.txt or http://www.cmake.org/HTML/Copyright.html for details.

     This software is distributed WITHOUT ANY WARRANTY; without even
     the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
     PURPOSE.  See the above copyright notices for more information.

=========================================================================*/

require_once(dirname(dirname(__DIR__))."/config/config.php");
require_once("include/common.php");
require_once("include/do_submit.php");
require_once("include/fnProcessFile.php");
require_once("include/pdo.php");
require_once("include/submission_functions.php");

ob_start();
set_time_limit(0);
ignore_user_abort(true);


// Parse script arguments. This file can be run in a web browser or called
// from the php command line executable.
//
// When called by command line, argv[1] is the projectid, and argv[2] may
// optionally be "--force" to force acquiring the processing lock.
// If "--force" is given, $force is 1, otherwise it's 0.
//
// When called by http, use "?projectid=1&force=1" to pass the info through
// php's _GET array. Use value 0 or 1 for force. If omitted, $force is 0.
//
echo "<pre>";
echo "begin processSubmissions.php\n";

$force = 0;

if (isset($argc) && $argc>1) {
    echo "argc, context is php command-line invocation...\n";
    echo "argc='" . $argc . "'\n";
    for ($i = 0; $i < $argc; ++$i) {
        echo "argv[" . $i . "]='" . $argv[$i] . "'\n";

        if ($argv[$i] == '--force') {
            $force = 1;
        }
    }

    $projectid = $argv[1];
} else {
    echo "no argc, context is web browser or some other non-command-line...\n";
    @$projectid = $_GET['projectid'];
    if ($projectid != null) {
        $projectid = pdo_real_escape_numeric($projectid);
    }

    @$force = $_GET['force'];
}

if (!is_numeric($projectid)) {
    echo "projectid/argv[1] should be a number\n";
    echo "</pre>";
    add_log("projectid '".$projectid."' should be a number",
    "ProcessSubmission",
    LOG_ERR, $projectid);
    return;
}


// Catch any fatal errors during processing
//
register_shutdown_function('ProcessSubmissionsErrorHandler', $projectid);


echo "projectid='$projectid'\n";
echo "force='$force'\n";

if (AcquireProcessingLock($projectid, $force)) {
    echo "AcquireProcessingLock returned true\n";

    ResetApparentlyStalledSubmissions($projectid);
    echo "Done with ResetApparentlyStalledSubmissions\n";

    ProcessSubmissions($projectid);
    echo "Done with ProcessSubmissions\n";

    DeleteOldSubmissionRecords($projectid);
    echo "Done with DeleteOldSubmissionRecords\n";

    if (ReleaseProcessingLock($projectid)) {
        echo "ReleasedProcessingLock returned true\n";
    } else {
        echo "ReleasedProcessingLock returned false\n";
    }
} else {
    echo "AcquireProcessingLock returned false\n";
    echo "Another process is already processing or there was a locking error\n";
}

echo "end processSubmissions.php\n";
echo "</pre>";

ob_end_flush();