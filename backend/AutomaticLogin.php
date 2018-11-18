<?php

/* 
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
add_action( 'wp_ajax_nopriv_grandcentral_auto_login', 'automaticLoginCallback' );
add_action( 'wp_ajax_grandcentral_auto_login', 'automaticLoginCallback' );
function automaticLoginCallback() {
    
    $public_key   = get_option( '_grandcentral_public_key' );
    if(empty($public_key)) {
        die('Site is not connected to a master instance.');
    }
    
    if (!isset($_GET['auto_login']) || !isset($_GET['signature']) || !isset($_GET['validate_at']) || !isset($_GET['salt'])) {
        die('something missing in request');
    }
    $validate_at = sanitize_text_field($_GET['validate_at']);
    $signature   = sanitize_text_field($_GET['signature']);
    
    if(validateSignature($signature) == false) {
        die('Signature not validated');
    } 
    
    if(get_transient( $signature ) !== false) {
        die('Signature already used.');
    }
    set_transient($signature, $validate_at, 2 * HOUR_IN_SECONDS);
    
    $username = (!isset($_GET['username'])) ? null : sanitize_text_field($_GET['username']);
    
    if ($username === null) {
        $users = get_users(array('role' => 'administrator', 'number' => 1, 'orderby' => 'ID'));
        if (empty($users[0]->user_login)) {
            die("We could not find an administrator user to use. Please contact support.");
        }
        $username = $users[0]->user_login;
    }
    
    $user = get_user_by('login', $username);
    if ($user === null) {
        die('User <strong>'.$username.'</strong> could not be found.');
    }
    wp_cookie_constants();
    wp_set_auth_cookie($user->ID, false, false);
    wp_set_auth_cookie($user->ID, false, true);
    $url = get_bloginfo('wpurl').'/wp-admin/index.php';
    
    wp_redirect( $url );
}
function validateSignature($signature)
{    
    $ID = get_option('_grandcentral_ID');
    $url = get_option('_grandcentral_website');

    $response = wp_remote_post( $url.'/api/wordpress/signature-verify', array(
	'method' => 'POST',
	'timeout' => 45,
	'redirection' => 5,
	'httpversion' => '1.0',
	'blocking' => true,
	'headers' => array(),
	'body' => ['key' => $ID,'signature' => $signature],
	'cookies' => array()
        )
    );
    if ( is_wp_error( $response ) ) {
       //$error_message = $response->get_error_message();
       return false;
    } else if (isset($response['body']) and $response['body'] != 'no') {
       return ($response['body'] == 'yes') ? true : false;
    }
}