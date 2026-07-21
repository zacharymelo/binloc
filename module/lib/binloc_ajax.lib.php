<?php
/* Copyright (C) 2026 Zachary Melo */

/**
 * \file    lib/binloc_ajax.lib.php
 * \ingroup binloc
 * \brief   Shared guards for the Binloc AJAX endpoints
 */

/**
 * Enforce POST method + a valid CSRF token on a mutating ajax endpoint.
 * Sends the error response and exits on failure.
 *
 * @param  string|null $token Token to check; defaults to the posted 'token' param
 * @return void
 */
function binloc_ajax_require_post_with_token($token = null)
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		http_response_code(405);
		print json_encode(array('error' => 'POST required'));
		exit;
	}

	if ($token === null || $token === '') {
		$token = GETPOST('token', 'alphanohtml');
	}

	$session_token = '';
	if (!empty($_SESSION['newtoken'])) {
		$session_token = $_SESSION['newtoken'];
	} elseif (!empty($_SESSION['token'])) {
		$session_token = $_SESSION['token'];
	}

	if (empty($token) || empty($session_token) || !hash_equals($session_token, (string) $token)) {
		http_response_code(403);
		print json_encode(array('error' => 'Invalid or missing CSRF token'));
		exit;
	}
}
