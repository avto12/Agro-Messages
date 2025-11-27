<?php
/**
 * Admin columns – Messages CPT
 * admin-columns.php
 */

if (!defined('ABSPATH')) exit;

add_filter( 'manage_messages_posts_columns', function( $columns ) {

   // Insert new column after Title
   $new = [];

   foreach ( $columns as $key => $label ) {
      $new[$key] = $label;

      if ($key === 'title') {
         $new['ag_recipient'] = __('Receiver', 'agro-messages');
      }
   }

   return $new;
} );

add_action( 'manage_messages_posts_custom_column', function( $column, $post_id ) {

   if ($column === 'ag_recipient') {

      // ACF user field
      $recipient_field = get_field('msg_recipient_user', $post_id);
      $user = null;

      if (is_object($recipient_field)) {
         $user = $recipient_field;
      } elseif (!empty($recipient_field)) {
         $user = get_userdata((int)$recipient_field);
      }

      if ($user) {
         echo esc_html($user->display_name);

         // Optional: show user role
         if (!empty($user->roles[0])) {
            echo ' <span style="color:#4caf50; font-size:12px;">(' . esc_html($user->roles[0]) . ')</span>';
         }

      } else {
         echo '—';
      }
   }

}, 10, 2 );