<?php
// Upload to public_html, visit once, then DELETE immediately
$to      = 'admin@mbge.co.za'; // change to your real email
$subject = 'MBGE mail() test';
$body    = 'If you receive this, PHP mail() is working on this server.';
$headers = 'From: noreply@gemb.co.za' . "\r\n" .
           'Content-Type: text/plain; charset=UTF-8';

$result = mail($to, $subject, $body, $headers);
echo $result ? 'mail() returned TRUE — check your inbox/spam'
             : 'mail() returned FALSE — mail() is not working on this server';
