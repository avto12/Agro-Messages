<?php
/**
 * Plugin Name: Agro Messages
 * Description: Admin → User შეტყობინებები (Email + მომხმარებლის Dashboard)
 * Version: 1.0
 * Domain Path: /languages
 * Text Domain: agro-messages
 * Author: Avtandil Kakachishvili
 */

if (!defined('ABSPATH')) exit;
require_once plugin_dir_path(__FILE__) . 'admin/admin-columns.php';
register_activation_hook(__FILE__, 'ag_messages_install_table');

function ag_messages_install_table() {
   global $wpdb;
   $table_name      = $wpdb->prefix . 'ag_messages';
   $charset_collate = $wpdb->get_charset_collate();

   $sql = "CREATE TABLE $table_name (
      id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id      BIGINT(20) UNSIGNED NOT NULL,
      sender_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
      recipient_id BIGINT(20) UNSIGNED NOT NULL,
      is_unread    TINYINT(1) NOT NULL DEFAULT 1,
      email_sent   TINYINT(1) NOT NULL DEFAULT 0,
      created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY  (id),
      UNIQUE KEY post_id (post_id),
      KEY recipient_id (recipient_id),
      KEY sender_id (sender_id)
   ) $charset_collate;";

   require_once ABSPATH . 'wp-admin/includes/upgrade.php';
   dbDelta($sql);
}


class AG_Messages {
   const CPT = 'messages'; // ACF CPT key

   public function __construct() {
      add_action('acf/save_post', [$this, 'handle_acf_save'], 20);
   }

   public function handle_acf_save($post_id) {
      if (get_post_type($post_id) !== self::CPT) {
         return;
      }

      if (get_post_status($post_id) !== 'publish') {
         return;
      }

      // ACF ველები
      $recipient_field = get_field('msg_recipient_user', $post_id); // User field

      if (!$recipient_field) {
         return;
      }

      if (is_object($recipient_field)) {
         $recipient_id = (int) $recipient_field->ID;
      } else {
         $recipient_id = (int) $recipient_field;
      }

      if (!$recipient_id) {
         return;
      }

      $recipient_user = get_user_by('id', $recipient_id);
      if (!$recipient_user || empty($recipient_user->user_email)) {
         return;
      }

      $current_user_id = get_current_user_id();
      if (!$current_user_id) {
         $current_user_id = 0; // სისტემა/ადმინი
      }

      global $wpdb;
      $table_name = $wpdb->prefix . 'ag_messages';

      // ჯერ შევამოწმოთ, უკვე არის თუ არა record ამ post_id-ზე
      $existing = $wpdb->get_var(
         $wpdb->prepare(
            "SELECT id FROM $table_name WHERE post_id = %d",
            $post_id
         )
      );

      $data = [
         'post_id'      => $post_id,
         'sender_id'    => $current_user_id,
         'recipient_id' => $recipient_id,
         'is_unread'    => 1,
         'email_sent'   => 0,
      ];

      $formats = ['%d','%d','%d','%d','%d'];

      if ($existing) {
         // update
         $wpdb->update(
            $table_name,
            $data,
            ['id' => (int) $existing],
            $formats,
            ['%d']
         );
      } else {
         // insert
         $wpdb->insert(
            $table_name,
            $data,
            $formats
         );
      }

      // Email გაგზავნა, მაგრამ ვნახოთ უკვე მივუგზავნეთ თუ არა
      // (email_sent ველის მიხედვით)

      $row = $wpdb->get_row(
         $wpdb->prepare(
            "SELECT email_sent FROM $table_name WHERE post_id = %d",
            $post_id
         )
      );

      if ($row && (int) $row->email_sent === 1) {
         return; // უკვე გაგზავნილია
      }

      $message = get_field('msg_body', $post_id);
      if ( ! $message ) {
         $message = __('გამოგეგზავნათ ახალი შეტყობინება.', 'agro-messages');
      }

      $sender_user = get_userdata($current_user_id);

      $sender_name  = $sender_user ? $sender_user->display_name : __('ადმინი', 'agro-messages');
      $sender_roles = '';

      if ($sender_user && !empty($sender_user->roles)) {
         $sender_roles = implode(', ', $sender_user->roles);
      }

      $message_body = '
          <div style="font-family:Arial, sans-serif; font-size:14px; color:#333;">
              <p><strong>გამომგზავნი:</strong> '. esc_html($sender_name) . ' <sup style="color:#000000; font-weight: 600; text-transform: capitalize;"> '. esc_html($sender_roles) . '</sup></p>
              <p>'. $message .'</p>
          </div>
      ';


      $to      = $recipient_user->user_email;
      $subject = get_the_title($post_id) ?: __('ახალი შეტყობინება', 'agro-messages');
      $headers = ['Content-Type: text/html; charset=UTF-8'];

      wp_mail($to, $subject, $message_body, $headers);

      $wpdb->update(
         $table_name,
         ['email_sent' => 1],
         ['post_id' => $post_id],
         ['%d'],
         ['%d']
      );
   }
}

new AG_Messages();

add_action('wp_ajax_ag_mark_message_read', 'ag_mark_message_read');
add_action('wp_ajax_nopriv_ag_mark_message_read', 'ag_mark_message_read');

function ag_mark_message_read() {
   if ( ! is_user_logged_in() ) {
      wp_send_json_error();
   }

   global $wpdb;
   $table_name      = $wpdb->prefix . 'ag_messages';
   $post_id         = intval($_POST['post_id']);
   $current_user_id = get_current_user_id();

   // განვაახლოთ მხოლოდ იმ შემთხვევაში, თუ ეს მესიჯი ამ მომხმარებელს ეკუთვნის
   $wpdb->update(
      $table_name,
      ['is_unread' => 0],
      ['post_id' => $post_id, 'recipient_id' => $current_user_id],
      ['%d'],
      ['%d','%d']
   );

   wp_send_json_success();
}


add_action('wp_ajax_ag_delete_message', 'ag_delete_message');
add_action('wp_ajax_nopriv_ag_delete_message', 'ag_delete_message');
function ag_delete_message() {
   if ( ! is_user_logged_in() ) {
      wp_send_json_error();
   }

   global $wpdb;
   $table   = $wpdb->prefix . 'ag_messages';
   $post_id = intval($_POST['post_id']);
   $user_id = get_current_user_id();

   // შევამოწმოთ, ნამდვილად ამ მომხმარებლის შეტყობინებაა?
   $exists = $wpdb->get_var(
      $wpdb->prepare(
         "SELECT COUNT(*) FROM $table WHERE post_id = %d AND recipient_id = %d",
         $post_id, $user_id
      )
   );

   if ( ! $exists ) {
      wp_send_json_error();
   }

   // custom table-დან წაშლა
   $wpdb->delete(
      $table,
      ['post_id' => $post_id],
      ['%d']
   );

   // WP პოსტის თრაშში გადაგდება
   wp_trash_post($post_id);

   wp_send_json_success();
}
