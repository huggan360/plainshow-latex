<?php
// The signed-in person's details for pages, from the user table that sign-in keeps in step.
$stmt = $conn->prepare('SELECT user_id, firstname, lastname, access_level, image_source FROM `user` WHERE user_id = ?');
$accountId = (int) ($_SESSION['user_id'] ?? 0);
$stmt->bind_param('i', $accountId);
$stmt->execute();
$f_username = $stmt->get_result()->fetch_assoc() ?: ['user_id' => $accountId, 'firstname' => '', 'lastname' => '', 'access_level' => 0, 'image_source' => ''];
$stmt->close();
$user_id = $accountId;
$user_name = trim($f_username['firstname'] . ' ' . $f_username['lastname']);
$access_level = (int) $f_username['access_level'];
$profile_picture = (string) $f_username['image_source'];
