<?php
/**
 * Plugin Name: Dev Theme
 * Description: This plugin can duplicate your theme to a special development theme that you can edit and use, while real users using your current theme
 * Version: 1.0.2
 */

! defined( 'ABSPATH' ) and exit;

class Dev_Theme {
    protected $real_template_path;
    protected $real_stylesheet_path;

    public function __construct() {
        $this->real_template_path = get_template_directory();
        $this->real_stylesheet_path = get_stylesheet_directory();

        add_filter( 'template', [$this, 'devtheme_user_template'] );
        add_filter( 'stylesheet', [$this, 'devtheme_user_stylesheet'] ); // only WP smaller 3*
        add_filter( 'option_template', [$this, 'devtheme_user_template'] );
        add_filter( 'option_stylesheet', [$this, 'devtheme_user_stylesheet'] );


        add_action( 'show_user_profile', [$this, 'devtheme_edit_user_profile'] );
        add_action( 'edit_user_profile', [$this, 'devtheme_edit_user_profile'] );

        add_action( 'personal_options_update', [$this, 'devtheme_edit_user_profile_update'] );
        add_action( 'edit_user_profile_update', [$this, 'devtheme_edit_user_profile_update'] );


        add_action('admin_init', [$this, 'deploy_dev_theme_to_main_theme']);
        add_action('admin_init', [$this, 'deploy_main_theme_to_dev_theme']);


        add_action('admin_notices', [$this, 'notices']);


        add_action('admin_menu', [$this, 'options_page']);
    }

    protected function getThemesDir() {
        return WP_CONTENT_DIR . '/themes/';
    }

    protected function getRealThemeDirName() {
        global $wpdb;

        if($this->is_child_theme() === true) {
            $real_theme_option = 'stylesheet';
        } else {
            $real_theme_option = 'template';
        }

        return $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", $real_theme_option ) )->option_value;
    }

    protected function getThemeDirPath() {
        return $this->getThemesDir() . $this->getRealThemeDirName();
    }

    protected function getBackupThemePath() {
        return $this->getThemeDirPath() . '-bk';
    }

    protected function getDevThemePath() {
        return $this->getThemesDir() . 'dev-theme';
    }

    protected function getBackupDevThemePath() {
        return $this->getDevThemePath() . '-bk';
    }

    protected function deleteDir($dirPath) {
        if (! is_dir($dirPath)) {
            throw new InvalidArgumentException("$dirPath must be a directory");
        }
        if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
            $dirPath .= '/';
        }
        $files = glob($dirPath . '{,.}[!.,!..]*', GLOB_MARK|GLOB_BRACE);
        foreach ($files as $file) {
            if (is_dir($file)) {
                $this->deleteDir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dirPath);
    }

    /**
     * Copy a file, or recursively copy a folder and its contents
     * @author      Aidan Lister <aidan@php.net>
     * @version     1.0.1
     * @link        http://aidanlister.com/2004/04/recursively-copying-directories-in-php/
     * @param       string   $source    Source path
     * @param       string   $dest      Destination path
     * @param       int      $permissions New folder creation permissions
     * @return      bool     Returns true on success, false on failure
     */
    protected function xcopy($source, $dest, $permissions = 0755) {
        // Check for symlinks
        if (is_link($source)) {
            return symlink(readlink($source), $dest);
        }

        // Simple copy for a file
        if (is_file($source)) {
            return copy($source, $dest);
        }

        // Make destination directory
        if (!is_dir($dest)) {
            mkdir($dest, $permissions);
        }

        // Loop through the folder
        $dir = dir($source);
        while (false !== $entry = $dir->read()) {
            // Skip pointers
            if ($entry == '.' || $entry == '..') {
                continue;
            }

            // Deep copy directories
            $this->xcopy("$source/$entry", "$dest/$entry", $permissions);
        }

        // Clean up
        $dir->close();
        return true;
    }

    protected function add_notice($notice, $classes = 'updated notice') {
        ?><div class="<?php echo esc_attr($classes); ?>">
        <p><?php echo esc_html($notice); ?></p>
        </div><?php
    }

    protected function isUserAllowedToDeploy() {
        $extra_conditions = apply_filters('dev_theme_deploy_extra_conditions', true);
        return (current_user_can('administrator') || current_user_can('super_admin')) && current_user_can( 'manage_options' ) && $extra_conditions;
    }

    /*
     * wp original is_child_theme will not work for us
     */
    protected function is_child_theme() {
        return ($this->real_template_path !== $this->real_stylesheet_path);
    }

    public function devtheme_titles( $title ) {
        return "** DEV ** $title";
    }

    public function devtheme_user_template( $template = '' ) {
        if ( get_user_meta( get_current_user_id(), 'devtheme_activate', true ) == 'checked' ) {
            if($this->is_child_theme() !== true) {//if is not child theme
                $template = 'dev-theme';
            }

            add_filter( 'admin_title', [$this, 'devtheme_titles'], 999 );
            add_filter( 'avf_title_tag', [$this, 'devtheme_titles'], 999 );
            add_filter( 'aioseop_title', [$this, 'devtheme_titles'], 999 );
        }

        return $template;
    }

    public function devtheme_user_stylesheet( $template = '' ) {
        if ( get_user_meta( get_current_user_id(), 'devtheme_activate', true ) == 'checked' ) {
            $template = 'dev-theme';

            add_filter( 'admin_title', [$this, 'devtheme_titles'], 999 );
            add_filter( 'avf_title_tag', [$this, 'devtheme_titles'], 999 );
            add_filter( 'aioseop_title', [$this, 'devtheme_titles'], 999 );
        }

        return $template;
    }

    public function devtheme_edit_user_profile( $user ) {
        if ( current_user_can( 'edit_theme_options' ) ) {
            $activate = get_user_meta( $user->ID, 'devtheme_activate', true );

            ?>
            <h3>Dev Theme Settings</h3>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">Dev Theme</th>
                        <td><label for="devtheme_activate"><input id="devtheme_activate" name="devtheme_activate" type="checkbox" value="checked" <?php if ( $activate == 'checked' ) echo 'checked'; ?>> Activate</label></td>
                    </tr>
                </tbody>
            </table>

            <input type="hidden" name="devtheme-user-profile-nonce" value="<?php echo wp_create_nonce('devtheme-user-profile'); ?>">
            <?php
        }
    }

    public function devtheme_edit_user_profile_update( $user_id ) {
        if ( current_user_can( 'edit_theme_options' ) && isset( $_POST[ "devtheme-user-profile-nonce" ] ) && wp_verify_nonce( $_POST[ "devtheme-user-profile-nonce" ], 'devtheme-user-profile' ) ) {
            update_user_meta( $user_id, 'devtheme_activate', sanitize_text_field($_POST['devtheme_activate']) );
        }
    }

    public function options_page() {
        if ( $this->isUserAllowedToDeploy() ) {
            add_menu_page(
                'DEV Theme',
                'DEV Theme',
                'manage_options',
                'dev-theme',
                [$this, 'options_page_html'],
                'dashicons-admin-tools',
                80
            );
        }
    }

    public function deploy_dev_theme_to_main_theme() {
        if(isset($_GET['deploy_dev_theme_to_main_theme']) && $this->isUserAllowedToDeploy()) {
            $template_dir_path = $this->getThemeDirPath();
            $backup_template_path = $this->getBackupThemePath();
            $dev_theme_path = $this->getDevThemePath();

            if(is_dir($backup_template_path)) {
                $this->deleteDir($backup_template_path);
            }

            if(!is_dir($backup_template_path)) {
                rename($template_dir_path, $backup_template_path);
            }

            if(is_dir($dev_theme_path) && !is_dir($template_dir_path)) {
                $this->xcopy($dev_theme_path, $template_dir_path);
            }

            if(wp_redirect(admin_url('admin.php?page=dev-theme&action_success=deploy_dev_theme_to_main_theme'))) exit;
        }
    }

    public function deploy_main_theme_to_dev_theme() {
        if(isset($_GET['deploy_main_theme_to_dev_theme']) && $this->isUserAllowedToDeploy()) {
            if(is_dir( $this->getBackupDevThemePath() )) {
                $this->deleteDir( $this->getBackupDevThemePath() );
            }

            if(!is_dir( $this->getBackupDevThemePath() ) && is_dir( $this->getDevThemePath() )) {
                rename($this->getDevThemePath(), $this->getBackupDevThemePath());
            }

            if(is_dir( $this->getThemeDirPath() ) && !is_dir( $this->getDevThemePath() )) {
                $this->xcopy($this->getThemeDirPath(), $this->getDevThemePath());
            }

            if(wp_redirect(admin_url('admin.php?page=dev-theme&action_success=deploy_main_theme_to_dev_theme'))) exit;
        }
    }

    public function options_page_html() {
        ?><h3>Dev Theme Settings</h3>
        <table class="form-table">
            <tbody>
                <tr>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=dev-theme&deploy_dev_theme_to_main_theme'); ?>" class="button blue">Copy from dev theme to main theme (<?php echo $this->getRealThemeDirName(); ?>)</a>
                    </td>
                </tr>
                <tr>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=dev-theme&deploy_main_theme_to_dev_theme'); ?>" class="button blue">Copy from main theme (<?php echo $this->getRealThemeDirName(); ?>) to dev theme (this will override dev theme)</a>
                    </td>
                </tr>
            </tbody>
        </table>

        <input type="hidden" name="devtheme-user-profile-nonce" value="<?php //echo wp_create_nonce('devtheme-user-profile'); ?>"><?php
    }

    public function notices() {
        if(isset($_GET['action_success']) && $_GET['action_success'] == 'deploy_dev_theme_to_main_theme') {
            $this->add_notice("Dev Theme deployed successfully to main theme (dev-theme -> {$this->getRealThemeDirName()})");
        }

        if(isset($_GET['action_success']) && $_GET['action_success'] == 'deploy_main_theme_to_dev_theme') {
            $this->add_notice("Main Theme deployed successfully to Dev Theme ({$this->getRealThemeDirName()} -> dev-theme)");
        }
    }
}

$dev_theme = new Dev_Theme();
