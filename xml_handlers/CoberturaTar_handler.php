<?php
/*=========================================================================
  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) Kitware, Inc. All rights reserved.
  See LICENSE or http://www.cdash.org/licensing/ for details.

  This software is distributed WITHOUT ANY WARRANTY; without even
  the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
  PURPOSE. See the above copyright notices for more information.
=========================================================================*/

/* TODO:
 * - Concatenate the source directory names with the class filenames to get the
 *   path inside the tarball. What we have right now won't work if the tarball
 *   is rooted at a different path than the filenames inside the Cobertura
 *   XMLs.
 * - Add a JSON file to the tarball specifying the source and build
 *   directories. This is necessary because Cobertura includes absolute
 *   filenames in its output, and CDash needs to know how to strip them. See
 *   GcovTar_handler.php for an example of how to do this.
 * - Add some tests.
 *
 * This code is just a proof of concept; it is in no way ready for production.
 */

require_once 'xml_handlers/abstract_handler.php';
require_once 'models/coverage.php';
require_once 'models/label.php';
require_once 'models/project.php';

class CoberturaTarHandler extends AbstractHandler
{
    private $CoverageSummaries;
    private $Coverages;
    private $CoverageFiles;
    private $CoverageFileLogs;
    private $CoverageFileLog;
    private $TarDir;

    /** Constructor */
    public function __construct($buildid)
    {
        parent::__construct($buildid, $buildid);

        $this->Build = new Build();
        $this->Build->Id = $buildid;
        $this->Build->FillFromId($buildid);

        $this->CoverageSummaries = array();
        $coverageSummary = new CoverageSummary();
        $coverageSummary->BuildId = $this->Build->Id;
        $this->CoverageSummaries['default'] = $coverageSummary;

        $this->Coverages = array();
        $this->CoverageFiles = array();
        $this->CoverageFileLogs = array();

        $this->CoverageFileLog = null;
    }

    /**
     * Parse a tarball of XML files.
     **/
    public function Parse($filename)
    {
        global $CDASH_BACKUP_DIRECTORY;

        // Create a new directory where we can extract our tarball.
        $dirName = $CDASH_BACKUP_DIRECTORY . DIRECTORY_SEPARATOR . pathinfo($filename, PATHINFO_FILENAME);
        mkdir($dirName);
        $this->TarDir = $dirName;
        $result = extract_tar($filename, $dirName);
        if ($result === false) {
            add_log('Could not extract ' . $filename . ' into ' . $dirName, 'CoberturaTarHandler::Parse', LOG_ERR);
            return false;
        }

        // Recursively search for .xml files and parse them.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirName),
            RecursiveIteratorIterator::CHILD_FIRST);
        $coverageSummary = $this->CoverageSummaries['default'];
        foreach ($iterator as $fileinfo) {
            // need the longest extension, so getExtension() won't do here.
            $ext = substr(strstr($fileinfo->getFilename(), '.'), 1);
            if ($ext === 'xml') {
                $this->ParseCoberturaFile($this->Build->Id, $fileinfo);
            }
        }

        // Record parsed coverage info to the database.
        foreach ($this->CoverageFileLogs as $path => $coverageFileLog) {
            $coverage = $this->Coverages[$path];
            $coverageFile = $this->CoverageFiles[$path];

            // Tally up how many lines of code were covered & uncovered.
            foreach ($coverageFileLog->Lines as $line) {
                $coverage->Covered = 1;
                if ($line == 0) {
                    $coverage->LocUntested += 1;
                } else {
                    $coverage->LocTested += 1;
                }
            }

            // Save these models to the database.
            $coverageFile->TrimLastNewline();
            $coverageFile->Update($this->Build->Id);
            $coverageFileLog->BuildId = $this->Build->Id;
            $coverageFileLog->FileId = $coverageFile->Id;
            $coverageFileLog->Insert();

            // Add this Coverage to our summary.
            $coverage->CoverageFile = $coverageFile;
            $coverage->BuildId = $this->Build->Id;
            $coverageSummary->AddCoverage($coverage);
        }

        // Insert coverage summaries
        $completedSummaries = array();
        foreach ($this->CoverageSummaries as $coverageSummary) {
            if (in_array($coverageSummary->BuildId, $completedSummaries)) {
                continue;
            }

            $coverageSummary->Insert();
            $coverageSummary->ComputeDifference();

            $completedSummaries[] = $coverageSummary->BuildId;
        }

        // Delete the directory when we're done.
        DeleteDirectory($dirName);
        return true;
    }

    public function startElement($parser, $name, $attributes)
    {
        parent::startElement($parser, $name, $attributes);

        if ($name == 'CLASS') {
            if (!array_key_exists('FILENAME', $attributes)) {
                return;
            }
            $path = $attributes['FILENAME'];

            if (!array_key_exists($path, $this->CoverageFileLogs)) {
                $pathEscaped = pdo_real_escape_string($path);

                $coverage = new Coverage();
                $coverageFile = new CoverageFile();
                $coverageFile->FullPath = trim($path);
                $coverageFileLog = new CoverageFileLog();

                $this->Coverages[$path] = $coverage;
                $this->CoverageFiles[$path] = $coverageFile;
                $this->CoverageFileLogs[$path] = $coverageFileLog;

                $fixedPath = str_replace('/', DIRECTORY_SEPARATOR, $path);
                $fullPath = $this->TarDir . DIRECTORY_SEPARATOR . $fixedPath;
                $file = fopen($fullPath, 'r');
                if (!$file) {
                    return;
                }
                $coverageFile->File = '';
                while (($line = fgets($file)) !== false) {
                    $coverageFile->File .= rtrim($line) . '<br>';
                }
                fclose($file);

                $this->CoverageFileLog = $coverageFileLog;
            } else {
                $this->CoverageFileLog = $this->CoverageFileLogs[$path];
            }
        } elseif ($name == 'LINE') {
            if (!array_key_exists('NUMBER', $attributes) ||
                !array_key_exists('HITS', $attributes)) {
                return;
            }

            $number = intval($attributes['NUMBER']);
            $hits = intval($attributes['HITS']);
            $this->CoverageFileLog->AddLine($number - 1, $hits);
        }
    }

    public function endElement($parser, $name)
    {
        parent::endElement($parser, $name);
        if ($name == 'CLASS') {
            $this->CoverageFileLog = null;
        }
    }

    public function text($parser, $data)
    {
    }

    /**
     * Parse an individual XML file.
     **/
    public function ParseCoberturaFile($buildid, $fileinfo)
    {
        // Parse this XML file.
        $fileContents = file_get_contents($fileinfo->getPath() . DIRECTORY_SEPARATOR . $fileinfo->getFilename());
        $parser = xml_parser_create();
        xml_set_element_handler($parser, array($this, 'startElement'), array($this, 'endElement'));
        xml_set_character_data_handler($parser, array($this, 'text'));
        xml_parse($parser, $fileContents, false);
    }
}
