<?php

add_action('admin_menu', 'grandcentral_settings_menu');
add_action( 'wp_dashboard_setup', 'register_studio98_dashboard_widget' );
function register_studio98_dashboard_widget() {
    wp_add_dashboard_widget(
        'my_dashboard_widget',
        'Studio98 Contact Support',
        'studio98_dashboard_widget_display'
    );

}

function studio98_dashboard_widget_display()
{
    $contact_info = get_option( '_grandcentral_contact_info' );
    if(empty($contact_info)) {

        $ID           = get_option( '_grandcentral_ID' );
        $website      = get_option( '_grandcentral_website' );

        $contactInfo  = validateApiKey($ID,$website);
        $contactInfo  = (isset($contactInfo->contact_info)) ? $contactInfo->contact_info : [];
        if(count($contactInfo) > 0) {
            $contact_info['logo'] = $contactInfo->logo;
            $contact_info['email'] = $contactInfo->email;
            $contact_info['phone'] = $contactInfo->phone;
            $contact_info['hours'] = $contactInfo->hours;
            $contact_info['address_1'] = $contactInfo->address_1;
            $contact_info['address_2'] = $contactInfo->address_2;
        }
    } else {
        $contact_info = unserialize($contact_info);
    }

    echo '<img src="'.$contact_info['logo'].'"><br><br>
        <b>Support Email</b>: <a href="mailto:'.$contact_info['email'].'">'.$contact_info['email'].'</a><br>
        <b>Website Support</b>: '.$contact_info['phone'].'<br><br>

        <b>Hours of Operation</b><br>
        '.$contact_info['hours'].'<br><br>

        '.$contact_info['address_1'].'<br>
        '.$contact_info['address_2'];
}

function grandcentral_settings_menu() {
    add_menu_page('Studio98 Settings', 'Studio98', 'add_users','studio98_settings_menu', 'studio98_settings_menu_function','', 83);

    $ID           = get_option( '_grandcentral_ID' );
    $website      = get_option( '_grandcentral_website' );
}

function studio98_settings_menu_function()
{
    if(isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'],'grandcentralFormAuthSettings')) {

        $url = sanitize_text_field($_POST['website_text']);
        if(!empty($url)) {
            $url = 'https://'.parse_url($url, PHP_URL_HOST);

            $validate = validateApiKey($_POST['ID_text'],$url,'yes');
            if($validate and isset($validate->public_key)) {
                update_option( '_grandcentral_ID', sanitize_text_field($_POST['ID_text']) );
                update_option( '_grandcentral_website', sanitize_text_field($url));
                update_option( '_grandcentral_public_key', $validate->public_key);
            } else {
                echo '<div class="updated" id="message"><p>WebMoniter: Sorry, we found an error. Please confirm your Organization Key and Access Token are correct and try again.</p></div>';
            }
        }

        $url = sanitize_text_field($_POST['website_export_text']);
        if(!empty($url)) {
            $url = 'https://'.parse_url($url, PHP_URL_HOST);

            $validate = validateApiKey($_POST['ID_export_text'],$url);
            if($validate and isset($validate->public_key)) {
                update_option( '_grandcentral_export_ID', sanitize_text_field($_POST['ID_export_text']) );
                update_option( '_grandcentral_export_website', sanitize_text_field($url));
                update_option( '_grandcentral_export_website', sanitize_text_field($url));
                update_option( '_grandcentral_export_public_key', $validate->public_key);
            } else {
                echo '<div class="updated" id="message"><p>Export: Sorry, we found an error. Please confirm your Organization Key and Access Token are correct and try again.</p></div>';
            }
        }
    }
    $out = '<div class="wrap">
            <div style="width:100px;padding-top:5px;" id="icon-grandcentral"><img src="'.GrandCentral_LOGO.'"></div><h2>Settings</h2>';

    $out .='<p>Authentication is required to use your Studio98 WordPress plugin.</p>';

    $out .='<p>To get your Private Key, please visit your dashboard. Your private authentication credential will be available there. Copy and paste them into the settings below!</p>';

    $out .='<form action="" method="post" name="grandcentral_settings">'.wp_nonce_field( 'grandcentralFormAuthSettings', '_wpnonce' );

    $ID               = get_option( '_grandcentral_ID' );
    $website          = get_option( '_grandcentral_website' );

    $out .= '<h2>Setting For Web Moniter</h2>';

    $out .='<table class="form-table">
      <tbody>
        <tr valign="top">
          <th scope="row"><label for="ID_text">Private Key</label></th>
          <td><input type="text" class="regular-text" value="'.$ID.'" name="ID_text"></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="website_text">Dashboard URL</label></th>
          <td><input type="text" class="regular-text" value="'.$website.'" name="website_text"></td>
        </tr>
     </tbody>
    </table>
    <p class="submit">
      <input type="submit" value="Save Changes" class="button-primary" id="submit" name="submit">
    </p>';
    $out .= '<h2>Setting For Export</h2>';


    $ID               = get_option( '_grandcentral_export_ID' );
    $website          = get_option( '_grandcentral_export_website' );
    $out .='<table class="form-table">
      <tbody>
        <tr valign="top">
          <th scope="row"><label for="ID_export_text">Private Key</label></th>
          <td><input type="text" class="regular-text" value="'.$ID.'" name="ID_export_text"></td>
        </tr>
        <tr valign="top">
          <th scope="row"><label for="website_export_text">Dashboard URL</label></th>
          <td><input type="text" class="regular-text" value="'.$website.'" name="website_export_text"></td>
        </tr>
     </tbody>
    </table>
    <p class="submit">
      <input type="submit" value="Save Changes" class="button-primary" id="submit" name="submit">
    </p>';


    $out .='</form>';


    if(!empty($ID) and !empty($website))
    {

        $post_types = validateApiKey($ID,$website);
        $post_types = (isset($post_types->types)) ? $post_types->types : [];
        $exported = grandcentral_total_exported();

        $out .='<h2>Export</h2>';


        $args = array(
            'public'   => true,
            '_builtin' => true
        );

        $customPostArgs = array(
            'public'   => true,
            '_builtin' => false
        );

        $wpDefaultPostTypes = get_post_types($args, 'names');
        $wpCustomPostTypes  = get_post_types($customPostArgs, 'names');
        $wpPostTypes        = (is_array($wpCustomPostTypes)) ? array_merge($wpDefaultPostTypes, $wpCustomPostTypes) : $wpDefaultPostTypes;

        $total_publish_posts = 0;
        foreach ($wpPostTypes as $wpPostType) {
            $count_posts            = wp_count_posts($wpPostType);
            $total_publish_posts    += $count_posts->publish;
        }

        $out .='You have total '.$total_publish_posts.' posts. which are ready to export. Total Exported : '.$exported;
        $out .= '<br><label for="website_text">Custom Post Type</label><br>';
        $out .= '<select class="regular-text" name="post_type">';

        if(count($post_types) > 0) {
            foreach ($post_types as $type) {
                $out .= '<option value="'.$type->slug.'">'.$type->title.'</option>';
            }
        }
        $out .= '</select>';



        $out .= '<br><label for="website_text">Wordpress Post Type</label><br>';
        $out .= '<select class="regular-text" name="site_post_type">';


        foreach ( $wpPostTypes as $post_type ) {
            $out .= '<option value="'.$post_type.'">'.ucfirst($post_type).'</option>';
        }

        $out .= '</select>';

        $out .= '<br><label><strong>Remove meta data after export posts? </strong>';
        $out .= '<input type="checkbox" name="remove_post_meta" value="1" /></label><br>';

        $out .='<p class="submit">
                <input onclick="exportPosts();" id="export_posts" type="button" value="Export Posts" class="button-primary" name="export">
              </p>';

    }

    $out .='</div>';// end of wrap div
    echo $out;
    ?>
    <script>
        function exportPosts()
        {
            jQuery('#export_posts').val('Exporting .... ');
            jQuery('#export_posts').attr('disabled','disabled');
            var post_type = jQuery('select[name=post_type]').val();
            var site_post_type = jQuery('select[name=site_post_type]').val();
            var remove_post_meta = 0;
            if(jQuery('[name=remove_post_meta]').is(':checked')) {
                remove_post_meta = 1;
            }
            //url: '<?php echo wp_nonce_url( admin_url('admin-ajax.php'), 'grandcentral_export_posts' );?>&do=export&action=grandcentral_export_posts',
            jQuery.ajax({
                type: 'POST',
                url: '<?php echo wp_nonce_url( admin_url('admin-ajax.php'), 'grandcentral_export_post_ids' );?>&do=export&action=grandcentral_export_post_ids',
                data: 'post_type='+post_type+'&site_post_type='+site_post_type+'&remove_post_meta='+remove_post_meta,
                success: function(data) {
                    location.reload();
                }
            });
        }
    </script>
    <?php
}

function validateApiKey($ID,$url,$hosting = 'no')
{
    $response = wp_remote_post( $url.'/api/wordpress/verify', array(
            'method' => 'POST',
            'timeout' => 60,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => ['key' => $ID,'wordpress' => home_url(),'hosting' => $hosting],
            'cookies' => array()
        )
    );
    if ( is_wp_error( $response ) ) {
        //$error_message = $response->get_error_message();
        return false;
    } else if (isset($response['body']) and $response['body'] != 'no') {
        return json_decode($response['body']);
    }
}

add_action('wp_ajax_grandcentral_export_post_ids', 'grandcentralExportPostIds');

function grandcentralExportPostIds() {
    global $wpdb;

    $post_type              = (isset($_POST['post_type']) and $_POST['post_type'] !='') ? sanitize_text_field($_POST['post_type']) : '';
    $site_post_type         = (isset($_POST['site_post_type']) and $_POST['site_post_type'] !='') ? sanitize_text_field($_POST['site_post_type']) : 'post';
    $remove_post_meta       = (isset($_POST['remove_post_meta'])) ? sanitize_text_field($_POST['remove_post_meta']) : 0;
    $permalink_structure    = get_option('permalink_structure');


    $alreadyExp = $wpdb->get_results( "SELECT post_id FROM ".$wpdb->prefix."postmeta WHERE meta_key = 'mogambooo_export' and meta_value=1");

    $excludePosts = [];
    if(count($alreadyExp) > 0 ){
        foreach ($alreadyExp as $item) {
            $excludePosts[] = $item->post_id;
        }
    }

    $query = "SELECT ID FROM ".$wpdb->prefix."posts WHERE post_type = '".$site_post_type."' and (post_status = 'publish' or post_status = 'draft' or post_status = 'private')";
    if(count($excludePosts) > 0) {
        $query .= " and ID not in (".implode(',', $excludePosts).")";
    }
    $query .= " ORDER BY post_date DESC, ID desc;";

    $posts = $wpdb->get_results($query);

    $post_ids = [];

    $i = 0;
    if(count($posts) > 0 ) {
        foreach ($posts AS $post) {
            if(count($post_ids[$i]) > 700) {
                $post_ids[++$i][] = $post->ID;
            }
            $post_ids[$i][] = $post->ID;
        }
    }
    if(count($post_ids) > 0 ){

        $option_meta_key        = 'remove_post_meta_'.$site_post_type;
        update_option($option_meta_key, $remove_post_meta);

        foreach ($post_ids as $post_id) {
            grandcentral_post_data_ids_to_origin($post_id, $post_type, $permalink_structure);
            if(count($post_ids) > 1) sleep(2);
        }
    }

    die('yes');
}

function grandcentral_post_data_ids_to_origin($posts,$post_type, $permalink_structure){
    $ID           = get_option( '_grandcentral_export_ID' );
    $url          = get_option( '_grandcentral_export_website' );

    $response = wp_remote_post( $url.'/api/wordpress/post-id', array(
            'method' => 'POST',
            'timeout' => 60,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => ['key' => $ID , 'data' => $posts,'type' => $post_type, 'permalink_structure' => $permalink_structure, 'site_url' => get_site_url()],
            'cookies' => array()
        )
    );

    if ( is_wp_error( $response ) ) {
        return false;
    } else {
        foreach($posts as $post) {
            update_post_meta($post, 'mogambooo_export', 1);
        }
        return true;
    }
}

add_action('wp_ajax_grandcentral_export_posts', 'grandcentralExportPosts');
function grandcentralExportPosts() {

    $post_type          = (isset($_POST['post_type']) and $_POST['post_type'] !='') ? sanitize_text_field($_POST['post_type']) : '';
    $site_post_type     = (isset($_POST['site_post_type']) and $_POST['site_post_type'] !='') ? sanitize_text_field($_POST['site_post_type']) : 'post';
    $permalink_structure = get_option('permalink_structure');

    $lastposts = grandcentral_remaining_export_posts($site_post_type);

    while ($lastposts ) {
        set_time_limit(0);
        sendPostsToGrandCentral($lastposts, $post_type, $permalink_structure);
        sleep(3);
        $lastposts = grandcentral_remaining_export_posts($site_post_type);
    }

    die('yes');
}

function sendPostsToGrandCentral($lastposts, $post_type, $permalink_structure) {
    $export = [];
    foreach ( $lastposts as $post ) :
        set_time_limit(0);

        $featured_img_url = get_the_post_thumbnail_url($post->ID,'full');
        $post_categories = wp_get_post_categories($post->ID );
        $cats = array();

        foreach($post_categories as $c){
            $cat = get_category( $c );

            $parent = $cat->parent;
            $parent = get_category($parent);
            $parent_name = $parent->name;

            $cats[] = [
                'name' => $cat->s,
                'slug' => $cat->slug ,
                'parent' => $parent->name,
                'parent_slug' => $parent->slug,
            ];
        }
        $post_meta = get_metadata('post', $post->ID);
        $export[] = [
            'wp_id'         => $post->ID,
            'title'         => $post->post_title,
            'post_status'   => $post->post_status,
            'slug'          => $post->post_name,
            'content'       => grandcentral_post_content($post),
            'excerpt'       => $post->post_excerpt,
            'posted_date'   => $post->post_date,
            'updated_at'    => $post->post_modified,
            'menu_order'    => $post->menu_order,
            'featured_image' => $featured_img_url,
            'categories' => $cats,
            'meta_data'  => $post_meta
        ];


    endforeach;
    if(count($export) > 0) {
        grandcentral_post_data_to_origin($export,$post_type, $permalink_structure);
        $export = [];
        return true;
    }
    return false;
}
function grandcentral_remaining_export_posts($site_post_type) {
    global $wpdb;

    $alreadyExp = $wpdb->get_results( "SELECT * FROM ".$wpdb->prefix."postmeta WHERE meta_key = 'mogambooo_export' and meta_value=1");
    $exclude = [];
    foreach($alreadyExp as $info){
        $exclude[] = $info->post_id;
    }
    $args = array(
        'numberposts' => 5,
        'exclude' => $exclude,
        'post_type' => $site_post_type,
        'post_status' => 'publish'
    );

    return get_posts( $args );
}

function grandcentral_post_content($post) {

    $content = nl2br($post->post_content);
    $galleries = get_post_galleries($post);
    $gallery_html = '';
    $float = is_rtl() ? 'right' : 'left';
    $itemwidth = 100;
    if(count($galleries) > 0) {
        foreach ($galleries as $instance => $gallery) {
            $selector = "gallery-{$instance}";
            $gallery_style = "<style type='text/css'>
                                #{$selector} {
                                    margin: auto;
                                }
                                #{$selector} .gallery-item {
                                    float: {$float};
                                    margin-top: 10px;
                                    text-align: center;
                                    width: {$itemwidth}%;
                                }
                                #{$selector} img {
                                    border: 2px solid #cfcfcf;
                                }
                                #{$selector} .gallery-caption {
                                    margin-left: 0;
                                }
                            </style>";

            $gallery_html .= $gallery_style;
            $gallery_html .= $gallery;
        }
        $content = str_replace('[gallery]', $gallery_html, $content);
        $content = str_replace('[ gallery ]', $gallery_html, $content);
        $content = str_replace('[gallery ]', $gallery_html, $content);
    }
    $content  = do_shortcode($content);
    $site_url = get_site_url();

    $dom = new \DOMDocument('1.0', 'UTF-8');
    $content = mb_convert_encoding($content, 'utf-8', mb_detect_encoding($content));
    @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
    foreach ($dom->getElementsByTagName('a') as $linkNode) {
        $linkNodeURL = $linkNode->getAttribute('href');

        if(!empty($linkNodeURL)) {
            $linkNodeURL = str_replace($site_url, '', $linkNodeURL);
            $linkNode->setAttribute('href', $linkNodeURL);
        }
    }
    foreach ($dom->getElementsByTagName('img') as $imageNode) {
        $imageNodeURL = $imageNode->getAttribute('srcset');

        if(!empty($imageNodeURL)) {
            $imageNodeURL = str_replace($site_url, '', $imageNodeURL);
            $imageNode->setAttribute('srcset', $imageNodeURL);
        }
    }
    $content = ($dom->saveHTML($dom->documentElement)). PHP_EOL . PHP_EOL;
    $content = str_replace('<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.0 Transitional//EN" "http://www.w3.org/TR/REC-html40/loose.dtd">', '', $content);
    $content = str_replace('<html>', '', $content);
    $content = str_replace('<body>', '', $content);
    $content = str_replace('</body>', '', $content);
    $content = str_replace('</html>', '', $content);

    return $content;
}


function grandcentral_post_data_to_origin($posts,$post_type, $permalink_structure)
{
    $ID           = get_option( '_grandcentral_export_ID' );
    $url          = get_option( '_grandcentral_export_website' );

    $response = wp_remote_post( $url.'/api/wordpress/posts', array(
            'method' => 'POST',
            'timeout' => 60,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => ['key' => $ID , 'data' => $posts,'type' => $post_type, 'permalink_structure' => $permalink_structure],
            'cookies' => array()
        )
    );

    if ( is_wp_error( $response ) ) {
        return false;
    } else {
        foreach($posts as $post) {
            update_post_meta($post['wp_id'], 'mogambooo_export', 1);
        }
        return true;
    }
}
function grandcentral_total_exported() {
    global $wpdb;
    $alreadyExp = $wpdb->get_row( "SELECT count(post_id) as total FROM ".$wpdb->prefix."postmeta WHERE meta_key = 'mogambooo_export' and meta_value=1");
    if(isset($alreadyExp->total))
        return $alreadyExp->total;
    return 0;
}


/**************** send request to gc server by get posts **********/
add_action( 'wp_ajax_nopriv_grandcentral_sync_posts', 'grandcentralSyncPosts' );
add_action('wp_ajax_grandcentral_sync_posts', 'grandcentralSyncPosts');

function grandcentralSyncPosts() {

    validate_ajax_connection();
    if(isset($_POST['data'])) {
        $post_ids = ($_POST['data']);
        $post_type = sanitize_text_field($_POST['post_type']);
        $permalink_structure = get_option('permalink_structure');
        if(is_array($post_ids) and count($post_ids) > 0 ) {
            $post = get_post($post_ids[0]);
            $wp_post_type = $post->post_type;
            $option_meta_key        = 'remove_post_meta_'.$wp_post_type;
            $remove_post_meta_value = get_option($option_meta_key, 0);
            $args = array(
                'numberposts'   => count($post_ids),
                'include'       => $post_ids,
                'post_status'   => ['publish', 'private', 'draft'],
                'post_type'     => $wp_post_type
            );
            $posts = get_posts( $args );
            $result = sendPostsToGrandCentral($posts, $post_type, $permalink_structure);
            if($result) {
                if($remove_post_meta_value == 1) {
                    $yoast_post_meta_keys = [
                        '_yoast_wpseo_title',
                        '_aioseop_title',
                        '_yoast_wpseo_metadesc',
                        '_aioseop_description',
                    ];
                    foreach ($post_ids AS $post_id) {
                        foreach ($yoast_post_meta_keys as $yoast_post_meta_key) {
                            delete_post_meta($post_id, $yoast_post_meta_key);
                        }
                    }
                }
                die('success');
            }
        }
    }
    die('error');
}

?>