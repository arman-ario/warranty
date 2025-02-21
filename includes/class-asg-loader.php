<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

class ASG_Loader {
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Admin
        if (is_admin()) {
            ASG_Admin::instance();
        }

        // Public
        ASG_Public::instance();

        // Load textdomain
        add_action('init', array($this, 'load_textdomain'));
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'after-sales-guarantee',
            false,
            dirname(plugin_basename(ASG_FILE)) . '/languages'
        );
    }
}