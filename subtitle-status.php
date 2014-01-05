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
add_action( 'admin_enqueue_scripts', 'substat_enqueue_widget_css' );
add_action( 'wp_enqueue_scripts', 'substat_enqueue_widget_css' );
add_action( 'widgets_init', create_function('', 'return register_widget("subtitle_status_widget_episode");'));
add_action( 'widgets_init', create_function('', 'return register_widget("subtitle_status_widget_listall");'));
add_action( 'plugins_loaded', 'substatus_update_db_check' );

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
$substatus_db_version = 3;

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
  visible        INT(1) DEFAULT 0,
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
  status_date    TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE (episode_id, workstation_id),
  FOREIGN KEY (episode_id) REFERENCES ${dbprefix}episode (id),
  FOREIGN KEY (workstation_id) REFERENCES ${dbprefix}workstation (id),
  FOREIGN KEY (status) REFERENCES ${dbprefix}statuscode (id)
);";
    substatus_create_table( $sql );

    $installed_version = get_option("substatus_db_version");
    if (empty($installed_version)) {
        // if we get here, it's a new install, and the schema will be correct
        // from the initialization, so make it the current version so we don't
        // run any update code.
        $installed_version = $substatus_db_version;
        add_option( "substatus_db_version", $substatus_db_version );
    }
    if ($installed_version < 2) {
        $wpdb->query("ALTER TABLE ${dbprefix}workstation_status ADD COLUMN status_date TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        $wpdb->query("UPDATE ${dbprefix}workstation_status SET status_date = NOW()");
    }
    if ($installed_version < 3) {
        $wpdb->query("ALTER TABLE ${dbprefix}episode ADD COLUMN visible INT(1) DEFAULT 0");
        $wpdb->query("UPDATE ${dbprefix}episode SET visible=1");
    }
    if ($installed_version < $substatus_db_version ) {
        update_option( "substatus_db_version", $substatus_db_version );
    }
}

function substatus_update_db_check() {
    global $substatus_db_version;
    if (get_site_option( 'substatus_db_version' ) != $substatus_db_version) {
        substatus_install();
    }
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

    //
    // UPDATE COLOR SETTINGS
    //
    if( isset($_POST[ $hidden_field_name ]) && $_POST[ $hidden_field_name ] == 'colors' ) {

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
<div class="updated"><p><strong><?php _e('color settings saved.', 'subtitle-status' ); ?></strong></p></div>
<?php

    }

    //
    // UPDATE EPISODE VISIBILITY
    //
    if( isset($_POST[ $hidden_field_name ]) && $_POST[ $hidden_field_name ] == 'episodes' ) {
        $results = $wpdb->get_results("SELECT * FROM ${dbprefix}episode ORDER BY id");
        foreach ($results as $episode) {
            $visible = 0;
            if (isset($_POST["substat-episode-visible-" . $episode->id])) { $visible = 1; }
            $wpdb->query($wpdb->prepare("UPDATE ${dbprefix}episode SET visible=%d WHERE id=%d", $visible, $episode->id));
        }
?>
<div class="updated"><p><strong><?php _e('episode settings saved.', 'subtitle-status' ); ?></strong></p></div>
<?php

    }

    //
    // UPDATE EPISODE WORKSTATION STATUSES
    //

    if( isset($_POST[ $hidden_field_name ]) && $_POST[ $hidden_field_name ] == 'episode-edit' ) {
        $episode_id = $_POST["substat-episode-id"];
        if ( isset($_POST["Submit"]) && $_POST["Submit"] == __("Cancel") ) {
            // User hit the cancel button - do nothing
        }
        else {
            // User hit Save - do the save
            $episode = substatus_get_episode($episode_id);
            $status = substatus_get_statusinfo();
            $passed = 1; // change to 0 if we get a form validation error
            foreach ($episode->workstation as $workstation) {
                if (isset($_POST["substat-wsnum-" . $workstation->id])) {
                    $formstatus = $_POST["substat-wsnum-" . $workstation->id];
                    if ($workstation->status != $formstatus && isset($status[$formstatus])) {
                        $workstation->newstatus = $formstatus;
                    }
                }
                else {
                    $passed = 0;
                }
            }
            if ($passed) {
                // everything validated, let's update the database and tell the user
?>
    <div class="updated">
<?php
                foreach ($episode->workstation as $workstation) {
                    if (isset($workstation->newstatus)) {
                        $wpdb->query($wpdb->prepare("UPDATE ${dbprefix}workstation_status SET status=%d WHERE episode_id=%d and workstation_id=%d", array($workstation->newstatus, $episode->id, $workstation->id)));
                        echo "<p>Changed <strong>" . htmlspecialchars($workstation->name) . "</strong>";
                        echo " on <strong>" . htmlspecialchars($episode->series_name) . " Episode " . htmlspecialchars($episode->episode_number) . "</strong>";
                        echo " from <strong>" . htmlspecialchars($status[$workstation->status]->abbrev) . "</strong>";
                        echo " to <strong>" . htmlspecialchars($status[$workstation->newstatus]->abbrev) . "</strong>";
                        echo ".</p>";
                    }
                }
?>
    </div>
<?php
            } else {
?>
<div class="error"><p><strong><?php _e('Form validation error.', 'subtitle-status' ); ?></strong></p></div>
<?php
            }
        }
        // echo "<pre>";  print_r($episode); echo "</pre>";

    }

    //
    // EDIT EPISODE WORKSTATION STATUSES SCREEN
    //

    $matches = array();
    if( isset($_GET["action"]) && $_GET["action"] == "edit-episode" && isset($_GET["episode-id"]) && preg_match("/^(\d+)\$/",$_GET["episode-id"], &$matches)) {
        $episode_id = $matches[0];

        $status = substatus_get_statusinfo();
        $episode = substatus_get_episode($episode_id);

?>
<div class="wrap">
<?php echo "<h3>", htmlspecialchars("$episode->series_name Episode $episode->episode_number:"), "</h3>";
// The numbers in the following table are all generated by graphviz's "dot" when
// given the dependency tree from the workstation_dependency table in the DB and
// output using dot's "imap" format. Eventually we'll use Image/GraphViz to
// generate this automatically
//
// digraph G {
//     rankdir=TB
//     ranksep=0.1
//     node [shape=box, fixedsize=true, height=0.5, width=2.5]
//     RAW [href="RAW"]
//     WRE [href="WRE"]
//     ......
//     RAW -> WRE
//     ......
// }
//
// gives output like:
//
// base referer
// rect RAW 5,5 245,53
// rect WRE 5,64 245,112
//
//                    RAW, WRE,  TL, TLC,Time,  TS,Edit,  QC, ENC, REL
$wsposleft = array(0,   5,   5, 269, 269,   5,   5, 269, 137, 137, 137);
$wspostop  = array(0,   5,  64,  64, 123, 123, 181, 181, 240, 299, 357);
$wsboxheight = 392; // top of bottom row + row height (357 + 35)
?>
<form name="substat-episode-edit" method="post" action="options-general.php?page=subtitle-status">
<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="episode-edit">
<input type="hidden" name="substat-episode-id" value="<?php echo $episode_id; ?>">
<div id="substat-wkstn-positions" style="height: <?php echo $wsboxheight; ?>px; position: relative;">
  <?php foreach (array(1,2,3,4,5,6,7,8,9,10) as $wsnum) { ?>
  <table style="width: 250px; height: 35px; position: absolute; left: <?php echo $wsposleft[$wsnum]; ?>px; top: <?php echo $wspostop[$wsnum]; ?>px;">
  <tr style="background-color: <?php echo "#",htmlspecialchars($episode->workstation[$wsnum]->color); ?>">
  <td style="width: 67%; padding: 0.5em;"><?php echo htmlspecialchars($episode->workstation[$wsnum]->name); ?></td>
  <td style="width: 33%; padding: 0.5em;"><select id="substat-wsnum-<?php echo $wsnum; ?>" name="substat-wsnum-<?php echo $wsnum; ?>">
<?php
        foreach ($status as $statustype) {
            ?><option value="<?php echo htmlspecialchars($statustype->id) ?>" onclick="void(this.parentNode.parentNode.parentNode.style.backgroundColor='<?php echo "#", htmlspecialchars($statustype->color); ?>');" <?php if ($episode->workstation[$wsnum]->status == $statustype->id) { echo 'selected="selected"'; } ?>><?php echo htmlspecialchars($statustype->abbrev); ?></option>
<?php } ?>
</select>
  </td>
  </tr>
</table>
<?php }
?>
</div>
<p class="submit" style="text-align: left;">
<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Cancel') ?>" />
</p>
</form>
</div>

<?php
    // END OF EPISODE WORKSTATION STATUS EDIT SCREEN
    }

    //
    // MAIN SETTINGS SCREEN
    //
    else {

    // Now display the settings editing screen

    echo '<div class="wrap">';

    // header

    echo "<h2>" . __( 'Subtitle Status Settings', 'subtitle-status' ) . "</h2>";

    // settings form

    ?>

<div class="postbox" id="status-colors-box" style="width: 200px; float: left; margin-right: 10px;">
<h3 style="padding-left: 10px;">Status Colors</h3>
<form name="substat-colors" method="post" action="">
<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="colors">
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
<p class="submit" style="text-align: center;">
<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
</p>
</form>
</div>

<div class="postbox" id="series-box" style="width: 300px; float: left; margin-right: 10px;">
<h3 style="padding-left: 10px;">Series</h3>
<table class="wp-list-table widefat">
<thead><tr><th>Series Name</th><th>Actions</th></tr></thead>
<tbody>
<?php
    $results = $wpdb->get_results("SELECT * FROM ${dbprefix}series ORDER BY id");
    foreach ($results as $series) {
?>
    <tr><td><?php echo htmlspecialchars($series->name); ?></td><td><a href="">Edit</a></td></tr>
<?php
    }
?>
<tr><td></td><td><a href="">Add Series</a></td></tr>
</tbody>
</table>
</div>

<div class="postbox" id="episodes-box" style="width: 400px; clear: both; margin-right: 10px;">
<h3 style="padding-left: 10px;">Episodes</h3>
<form name="substat-episodes" method="post" action="">
<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="episodes">
<table class="wp-list-table widefat">
<thead><tr><th>Episode</th><th>Visible</th><th>Actions</th></tr></thead>
<tbody>
<?php
    $results = $wpdb->get_results("SELECT * FROM ${dbprefix}episode ORDER BY id");
    foreach ($results as $episode) {
?>
    <tr>
      <td><?php substatus_emit_widget_episode( $episode->id ); ?></td>
      <td><input type="checkbox" name="substat-episode-visible-<?php echo htmlspecialchars($episode->id) ?>" value="1" <?php if ($episode->visible) { echo 'checked="checked"'; } ?>></td>
      <td><a href="?page=subtitle-status&amp;action=edit-episode&amp;episode-id=<?php echo htmlspecialchars($episode->id) ?>">Edit</a></td>
    </tr>
<?php
    }
?>
<tr><td colspan="2">
<p class="submit" style="text-align: center;">
<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
</p>
</td><td><a href="">Add Episode</a></td></tr>
</tbody>
</table>
</form>
</div>


</div>
<?php
    }
} // END OF MAIN SETTINGS SCREEN

// convenience function to load the status names and colors
function substatus_get_statusinfo() {
    global $wpdb;
    $dbprefix = $wpdb->prefix . "substat_";

    global $substat_status;
    if (isset($substat_status)) {
        return $substat_status;
    }

    $status = array();
    $results = $wpdb->get_results("SELECT * FROM ${dbprefix}statuscode ORDER BY id");
    foreach ($results as $statusresult) {
        $status[$statusresult->id] = (object) array();
        $status[$statusresult->id]->id = $statusresult->id;
        $status[$statusresult->id]->abbrev = $statusresult->abbrev;
        $status[$statusresult->id]->color = $statusresult->color;
    }
    return $status;
}

// convenience function to get all the data related to an individual episode out of the database
function substatus_get_episode($episode_id) {
    global $wpdb;
    $dbprefix = $wpdb->prefix . "substat_";

    $status = substatus_get_statusinfo();
    $episode = $wpdb->get_row($wpdb->prepare("SELECT * FROM ${dbprefix}episode WHERE id=%d", array($episode_id)));
    $episode->series_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM ${dbprefix}series WHERE id=%d", array($episode->series_id)));
    $episode->workstation = array();
    $results = $wpdb->get_results("SELECT * FROM ${dbprefix}workstation ORDER BY id");
    foreach ($results as $wsresult) {
        $episode->workstation[$wsresult->id] = $wsresult;
    }
    $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM ${dbprefix}workstation_status WHERE episode_id=%d ORDER BY workstation_id", array($episode_id)));
    foreach ($results as $wsstatus) {
        $episode->workstation[$wsstatus->workstation_id]->status = $status[$wsstatus->status]->id;
        $episode->workstation[$wsstatus->workstation_id]->statusname = $status[$wsstatus->status]->abbrev;
        $episode->workstation[$wsstatus->workstation_id]->color = $status[$wsstatus->status]->color;
        $episode->workstation[$wsstatus->workstation_id]->workername = $wsstatus->workername;
    }
    return $episode;
}

// WIDGET GRID DISPLAY
// Used both by the widget code and the admin screen
function substatus_emit_widget_episode($episode_id) {
    global $wpdb;
    $dbprefix = $wpdb->prefix . "substat_";

    $episode = substatus_get_episode($episode_id);
?>
<div class="substatus_episode_widget">
<?php echo "<b>", htmlspecialchars("$episode->series_name Episode $episode->episode_number:"), "</b>"; ?><br />
  <table>
  <tr>
  <?php foreach (array(1,2,5,6) as $wsnum) { ?>
  <td title="<?php echo htmlspecialchars($episode->workstation[$wsnum]->name), " - ", htmlspecialchars($episode->workstation[$wsnum]->statusname); ?>"
      style="background-color: <?php echo "#", htmlspecialchars($episode->workstation[$wsnum]->color); ?>">
     <?php echo htmlspecialchars($episode->workstation[$wsnum]->abbrev); ?>
  </td>
<?php }
  foreach (array(8,9,10) as $wsnum) { ?>
  <td title="<?php echo htmlspecialchars($episode->workstation[$wsnum]->name), " - ", htmlspecialchars($episode->workstation[$wsnum]->statusname); ?>"
      style="background-color: <?php echo "#", htmlspecialchars($episode->workstation[$wsnum]->color); ?>" rowspan="2">
     <?php echo htmlspecialchars($episode->workstation[$wsnum]->abbrev); ?>
  </td>
<?php } ?>
  </tr>
  <tr>
  <td style="border: none;"></td>
<?php foreach (array(3,4,7) as $wsnum) { ?>
  <td title="<?php echo htmlspecialchars($episode->workstation[$wsnum]->name), " - ", htmlspecialchars($episode->workstation[$wsnum]->statusname); ?>"
      style="background-color: <?php echo "#", htmlspecialchars($episode->workstation[$wsnum]->color); ?>">
     <?php echo htmlspecialchars($episode->workstation[$wsnum]->abbrev); ?>
  </td>
<?php } ?>
  </tr>
  </table>
</div>
<?php
}

//
// THE SINGLE EPISODE WIDGET
//
class subtitle_status_widget_episode extends WP_Widget {
    function subtitle_status_widget_episode() {
        parent::WP_Widget(false, $name = "Subtitle Status Single Episode");
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

//
// THE ALL-EPISODES WIDGET
//
class subtitle_status_widget_listall extends WP_Widget {
    function subtitle_status_widget_listall() {
        parent::WP_Widget(false, $name = "Subtitle Status All Episodes");
    }
    function form($instance) {
?>
<p>
No configuration required.
</p>
<?php

    }
    function update($new_instance, $old_instance) {
        $instance = $old_instance;
        return $instance;
    }
    function widget($args, $instance) {
        global $wpdb;
        $dbprefix = $wpdb->prefix . "substat_";
        extract($args);
        echo $before_widget;
        $episodes = $wpdb->get_results("SELECT * FROM ${dbprefix}episode WHERE visible = 1 ORDER BY series_id, episode_number");
        foreach ($episodes as $episode) {
            substatus_emit_widget_episode( $episode->id );
        }
        echo $after_widget;
    }
}
?>
