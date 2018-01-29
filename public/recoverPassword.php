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

include dirname(__DIR__) . '/config/config.php';
require_once 'include/pdo.php';
include_once 'include/common.php';
include_once 'include/version.php';
require_once 'include/cdashmail.php';
require_once 'models/user.php';

$xml = begin_XML_for_XSLT();
$xml .= '<title>Recover password</title>';
if (isset($CDASH_NO_REGISTRATION) && $CDASH_NO_REGISTRATION == 1) {
    $xml .= add_XML_value('noregister', '1');
}

@$recover = $_POST['recover'];
if ($recover) {
    $email = $_POST['email'];
    $user = new User();
    $userid = $user->GetIdFromEmail($email);
    if (!$userid) {
        // Don't reveal whether or not this is a valid account.
        $xml .= '<message>A confirmation message has been sent to your inbox.</message>';
    } else {
        $user->Id = $userid;
        $user->Fill();
        if ($user->Password == '*') {
            // This is a no-login account, but don't reveal that.
            $xml .= '<message>A confirmation message has been sent to your inbox.</message>';
        } else {
            // Create a new password
            $password = generate_password(10);

            $currentURI = get_server_URI();
            $url = $currentURI . '/user.php';

            $text = "Hello,\n\n You have asked to recover your password for CDash.\n\n";
            $text .= 'Your new password is: ' . $password . "\n";
            $text .= 'Please go to this page to login: ';
            $text .= "$url\n";
            $text .= "\n\nGenerated by CDash";

            if (cdashmail("$email", 'CDash password recovery', $text)) {
                // If we can send the email we update the database
                $passwordHash = User::PasswordHash($password);
                if ($passwordHash === false) {
                    $xml .= '<warning>Failed to hash new password</warning>';
                } else {
                    $user->Id = $userid;
                    $user->Fill();
                    $user->Password = $passwordHash;
                    $user->Save();
                    $xml .= '<message>A confirmation message has been sent to your inbox.</message>';
                }
            } else {
                $xml .= '<warning>Cannot send recovery email</warning>';
            }
        }
    }
}

$xml .= '</cdash>';

// Now doing the xslt transition
generate_XSLT($xml, 'recoverPassword');
