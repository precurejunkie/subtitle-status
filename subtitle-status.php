<?php
/*
Plugin Name: Subtitle Status Widget
Plugin URI: http://www.curecom.net/subtitle-status
Description: A widget to show the progress towards fansub releases
Version: 1.0
Author: PrecureJunkie
Author URI: http://twitter.com/precurejunkie
Author Email: precurejunkie@gmail.com
*/

add_action( 'admin_menu', 'substatus_plugin_menu' );
register_activation_hook( __FILE__, 'substatus_install' );
register_activation_hook( __FILE__, 'substatus_install_data' );
add_action( 'admin_enqueue_scripts', 'substat_enqueue_color_picker' );
add_action( 'wp_enqueue_scripts', 'substat_enqueue_widget_css' );
add_action( 'widgets_init', create_function('', 'return register_widget("subtitle_status_widget");'));
function substat_enqueue_color_picker( $hook_suffix ) {
    // first check that $hook_suffix is appropriate for your admin page
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_script( 'subtitle-status', plugins_url('substatus.js', __FILE__ ), array( 'wp-color-picker' ), false, true );
}
function substat_enqueue_widget_css() {
    wp_register_style( 'subtitle-status', plugins_url('substatus.css', __FILE__) );
    wp_enqueue_style( 'subtitle-status' );
}

global $substatus_db_version;
$substatus_db_version = "1.0";

function substatus_create_table($ddl) {
    global $wpdb;
    $table = "";
    if (preg_match("/create table\s+(\w+)\s/i", $ddl, $match)) {
        $table = $match[1];
    } else {
        return false;
    }
    foreach ($wpdb->get_col("SHOW TABLES",0) as $tbl ) {
        if ($tbl == $table) {
            return true;
        }
    }
    // if we get here it doesn't exist yet, so create it
    $wpdb->query($ddl);
    // check if it worked
    foreach ($wpdb->get_col("SHOW TABLES",0) as $tbl ) {
        if ($tbl == $table) {
            return true;
        }
    }
    return false;
}

function substatus_install() {
    /* Reference: http://codex.wordpress.org/Creating_Tables_with_Plugins */

    global $wpdb;
    global $substatus_db_version;

    $dbprefix = $wpdb->prefix . "substat_";

    $sql = "CREATE TABLE ${dbprefix}series (
  id   MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  name VARCHAR(128),
  PRIMARY KEY (id)
);";
    substatus_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}episode (
  id             MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  series_id      MEDIUMINT(9),
  episode_number VARCHAR(20),
  PRIMARY KEY (id),
  UNIQUE (episode_number),
  FOREIGN KEY (series_id) REFERENCES ${dbprefix}series (id)
);";
    substatus_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}workstation (
  id          MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  abbrev      VARCHAR(5),
  name        VARCHAR(30),
  description TEXT,
  PRIMARY KEY (id),
  UNIQUE (abbrev)
);";
    substatus_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}statuscode (
  id          MEDIUMINT(9) NOT NULL,
  abbrev      VARCHAR(20),
  color       VARCHAR(6) NOT NULL DEFAULT 'aaaaaa',
  PRIMARY KEY (id),
  UNIQUE (abbrev)
);";
    substatus_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}workstation_dependency (
  id             MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  workstation_id MEDIUMINT(9),
  requires       MEDIUMINT(9),
  PRIMARY KEY (id),
  UNIQUE (workstation_id,requires),
  INDEX (requires),
  FOREIGN KEY (workstation_id) REFERENCES ${dbprefix}workstation (id),
  FOREIGN KEY (requires) REFERENCES ${dbprefix}workstation (id) 
);";
    substatus_create_table( $sql );

    $sql = "CREATE TABLE ${dbprefix}workstation_status (
  id             MEDIUMINT(9) NOT NULL AUTO_INCREMENT,
  episode_id     MEDIUMINT(9),
  workstation_id MEDIUMINT(9),
  status         MEDIUMINT(9),
  workername     VARCHAR(30),
  status_date    TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE (episode_id, workstation_id),
  FOREIGN KEY (episode_id) REFERENCES ${dbprefix}episode (id),
  FOREIGN KEY (workstation_id) REFERENCES ${dbprefix}workstation (id),
  FOREIGN KEY (status) REFERENCES ${dbprefix}statuscode (id)
);";
    substatus_create_table( $sql );

   add_option( "substatus_db_version", $substatus_db_version );
}

function substatus_install_data() {
    global $wpdb;

    $dbprefix = $wpdb->prefix . "substat_";

    $wpdb->query("INSERT INTO ${dbprefix}workstation (id, abbrev, name) VALUES
(1,'RAW','Obtain Raw'),
(2,'WRE','Work Raw Encode'),
(3,'TL','Translation'),
(4,'TLC','Translation Check'),
(5,'Time','Timing'),
(6,'TS','Typesetting'),
(7,'Edit','Editing'),
(8,'QC','Quality Check'),
(9,'Enc','Final Encodes'),
(10,'Rel','Release');");

    $wpdb->query("INSERT INTO ${dbprefix}statuscode (id, abbrev, color) VALUES
(1, 'blocked',     'ff0000'),
(2, 'waiting',     'dddd00'),
(3, 'in progress', '7777ff'),
(4, 'skipped',     '000000'),
(5, 'completed',   '00dd00');");

    $wpdb->query("INSERT INTO ${dbprefix}workstation_dependency (workstation_id, requires) VALUES
(2,1),
(4,3),
(5,2),
(5,3),
(6,5),
(7,4),
(7,5),
(8,7),
(8,6),
(9,8),
(10,9);");

}

function substatus_plugin_menu() {
	add_options_page( 'Subtitle Status', 'Subtitle Status', 'manage_options', 'subtitle-status', 'substatus_options' );
}

function substatus_options() {

    global $wpdb;

    $dbprefix = $wpdb->prefix . "substat_";
    $hidden_field_name = 'sts_submit_hidden';

    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    // See if the user has posted us some information
    // If they did, this hidden field will be set to 'Y'
    if( isset($_POST[ $hidden_field_name ]) && $_POST[ $hidden_field_name ] == 'Y' ) {

        // Put an settings updated message on the screen
        $results = $wpdb->get_results("SELECT id FROM ${dbprefix}statuscode ORDER BY id");
        foreach ($results as $statuscode) {
            $colorid = $statuscode->id;
            if (isset($_POST["substat-".$colorid]) && isset($_POST["substat-".$colorid."-hidden"]) &&
            ($_POST["substat-".$colorid] != $_POST["substat-".$colorid."-hidden"])) {
                $newcolor = stripslashes( $_POST["substat-".$colorid] );
                preg_replace( "/^#/", "", $newcolor );
                $wpdb->update( "${dbprefix}statuscode", Array( "color" => "$newcolor" ), Array( "id" => $colorid ) );
            }
        }
?>
<div class="updated"><p><strong><?php _e('settings saved.', 'subtitle-status' ); ?></strong></p></div>
<?php

    }

    // Now display the settings editing screen

    echo '<div class="wrap">';

    // header

    echo "<h2>" . __( 'Subtitle Status Settings', 'subtitle-status' ) . "</h2>";

    // settings form

    ?>

<form name="form1" method="post" action="">
<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="Y">

<div class="postbox" id="series-box" style="width: 300px">
<h3>Series</h3>
<table class="wp-list-table widefat">
<thead><tr><th>Series Name</th><th>Actions</th></tr></thead>
<tbody>
<tr><td>Dokidoki! Precure</td><td><a href="">Edit</a> <a href="">Delete</a></td></tr>
<tr><td>Fresh Pretty Cure</td><td><a href="">Edit</a> <a href="">Delete</a></td></tr>
</tbody>
</table>
</div>

<div class="postbox" id="status-colors-box" style="width: 200px">
<h3>Status Colors</h3>
<table class="wp-list-table widefat">
<thead>
<tr><th>Status</th><th>Color</th></tr>
</thead>
<tbody>
<?php
$results = $wpdb->get_results("SELECT * FROM ${dbprefix}statuscode ORDER BY id");
foreach ($results as $statuscode) {
    ?>
    <tr>
        <td><?=htmlspecialchars($statuscode->abbrev, ENT_COMPAT | ENT_HTML401, 'UTF-8', true)?></td>
        <td>
            <input type="hidden" name="substat-color-<?=htmlspecialchars($statuscode->id, ENT_COMPAT | ENT_HTML401, 'UTF-8', true) ?>-hidden" value="#<?=htmlspecialchars($statuscode->color, ENT_COMPAT | ENT_HTML401, 'UTF-8', true)?>">
            <input type="text" name="substat-color-<?=htmlspecialchars($statuscode->id, ENT_COMPAT | ENT_HTML401, 'UTF-8', true) ?>" class="substat-color-picker" value="#<?=htmlspecialchars($statuscode->color, ENT_COMPAT | ENT_HTML401, 'UTF-8', true)?>">
        </td>
    </tr>
    <?php
}
?>
</tbody>
</table>
</div>

<p class="submit">
<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
</p>

</form>
</div>
<?php
}

function substatus_emit_widget_episode($episode_id) {
    global $wpdb;
    $dbprefix = $wpdb->prefix . "substat_";

    $statusabbr = array();
    $statuscolor = array();
    $workstation = array();
    $results = $wpdb->get_results("SELECT * FROM ${dbprefix}statuscode ORDER BY id");
    foreach ($results as $statusresult) {
        $statusabbr[$statusresult->id] = $statusresult->abbrev;
        $statuscolor[$statusresult->id] = $statusresult->color;
    }
    $results = $wpdb->get_results("SELECT * FROM ${dbprefix}workstation ORDER BY id");
    foreach ($results as $wsresult) {
        $workstation[$wsresult->id] = $wsresult;
    }
    $episode_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM ${dbprefix}episode WHERE id=%d", array($episode_id)));
    $episode_num = $episode_data->episode_number;
    $series_id = $episode_data->series_id;
    $series_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM ${dbprefix}series WHERE id=%d", array($series_id)));
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM ${dbprefix}workstation_status WHERE episode_id=%d ORDER BY workstation_id", array($episode_id)));
    foreach ($results as $wsstatus) {
        $workstation[$wsstatus->workstation_id]->status = $statusabbr[$wsstatus->status];
        $workstation[$wsstatus->workstation_id]->color = $statuscolor[$wsstatus->status];
        $workstation[$wsstatus->workstation_id]->workername = $wsstatus->workername;
    }
?>
<div class="substatus_episode_widget">
<?php echo "<b>", htmlspecialchars("$series_name Episode $episode_num:"), "</b>"; ?><br />
  <table>
  <tr>
  <?php foreach (array(1,2,5,6) as $wsnum) { ?>
  <td title="<?php echo htmlspecialchars($workstation[$wsnum]->name), " - ", htmlspecialchars($workstation[$wsnum]->status); ?>"
      style="background-color: <?php echo "#", htmlspecialchars($workstation[$wsnum]->color); ?>">
     <?php echo htmlspecialchars($workstation[$wsnum]->abbrev); ?>
  </td>
<?php }
  foreach (array(8,9,10) as $wsnum) { ?>
  <td title="<?php echo htmlspecialchars($workstation[$wsnum]->name), " - ", htmlspecialchars($workstation[$wsnum]->status); ?>"
      style="background-color: <?php echo "#", htmlspecialchars($workstation[$wsnum]->color); ?>" rowspan="2">
     <?php echo htmlspecialchars($workstation[$wsnum]->abbrev); ?>
  </td>
<?php } ?>
  </tr>
  <tr>
  <td style="border: none;"></td>
<?php foreach (array(3,4,7) as $wsnum) { ?>
  <td title="<?php echo htmlspecialchars($workstation[$wsnum]->name), " - ", htmlspecialchars($workstation[$wsnum]->status); ?>"
      style="background-color: <?php echo "#", htmlspecialchars($workstation[$wsnum]->color); ?>">
     <?php echo htmlspecialchars($workstation[$wsnum]->abbrev); ?>
  </td>
<?php } ?>
  </tr>
  </table>
</div>
<?php
}

class subtitle_status_widget extends WP_Widget {
    function subtitle_status_widget() {
        parent::WP_Widget(false, $name = "Subtitle Status");
    }
    function form($instance) {
        global $wpdb;
        $dbprefix = $wpdb->prefix . "substat_";
        if( $instance) {
            $episode_id = esc_attr($instance['episode_id']);
        } else {
            $episode_id = 0;
        }
?>
<p>
<label for="<?php echo $this->get_field_id('episode_id'); ?>">Episode:</label>
<select name="<?php echo $this->get_field_name('episode_id'); ?>" id="<?php echo $this->get_field_id('episode_id'); ?>" class="widefat">
<?php
$options = $wpdb->get_results("SELECT e.id, name, episode_number FROM ${dbprefix}episode e INNER JOIN ${dbprefix}series s ON e.series_id = s.id ORDER BY name, episode_number");
foreach ($options as $option) {
echo '<option value="' . htmlspecialchars($option->id) . '" id="' . htmlspecialchars($option->id) . '"', $episode_id == $option->id ? ' selected="selected"' : '', '>', htmlspecialchars($option->name . ' ' . $option->episode_number), '</option>';
}
?>
</select>
</p>
<?php

    }
    function update($new_instance, $old_instance) {
        $instance = $old_instance;
        // Fields
        $instance['episode_id'] = strip_tags($new_instance['episode_id']);
        return $instance;
    }
    function widget($args, $instance) {
        extract($args);
        echo $before_widget;
        substatus_emit_widget_episode( $instance['episode_id'] );
        echo $after_widget;
    }
}

?>
