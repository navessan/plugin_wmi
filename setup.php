<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2019 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

function plugin_wmi_install() {
	api_plugin_register_hook('wmi', 'config_arrays',        'wmi_config_arrays',        'setup.php');
	api_plugin_register_hook('wmi', 'config_form',          'wmi_config_form',          'setup.php');
	api_plugin_register_hook('wmi', 'config_settings',      'wmi_config_settings',      'setup.php');
	api_plugin_register_hook('wmi', 'draw_navigation_text', 'wmi_draw_navigation_text', 'setup.php');
	api_plugin_register_hook('wmi', 'api_device_save',      'wmi_api_device_save',      'setup.php');
	api_plugin_register_hook('wmi', 'data_input_sql_where', 'wmi_data_input_sql_where', 'setup.php');
	api_plugin_register_hook('wmi', 'poller_bottom',        'wmi_poller_bottom',        'setup.php');

	api_plugin_register_hook('wmi', 'device_template_edit',   'wmi_device_template_edit',   'setup.php');
	api_plugin_register_hook('wmi', 'device_template_top',    'wmi_device_template_top',    'setup.php');
	api_plugin_register_hook('wmi', 'device_edit_pre_bottom', 'wmi_device_edit_pre_bottom', 'setup.php');
	api_plugin_register_hook('wmi', 'api_device_new',         'wmi_api_device_new',         'setup.php');

	api_plugin_register_realm('wmi', 'wmi_accounts.php,wmi_queries.php,wmi_tools.php', __('WMI Management'), 1);

	plugin_wmi_setup_tables();
}

function plugin_wmi_uninstall() {
	global $config;

	return true;

	include_once($config['base_path'] . '/lib/api_data_source.php');
	include_once($config['base_path'] . '/lib/api_graph.php');

	db_execute('DROP TABLE IF EXISTS `wmi_user_accounts`');
	db_execute('DROP TABLE IF EXISTS `wmi_wql_queries`');
	db_execute('DROP TABLE IF EXISTS `host_template_wmi_query`');
	db_execute('DROP TABLE IF EXISTS `host_wmi_accounts`');
	db_execute('DROP TABLE IF EXISTS `host_wmi_query`');
	db_execute('DROP TABLE IF EXISTS `host_wmi_cache`');

	/* remove graphs and data sources based upon WMI information */
	$id = db_fetch_cell('SELECT GROUP_CONCAT(id)
		FROM data_input
		WHERE hash IN("4af550dfe8b451579054d038ad62ba3e","42e584b81075f6ad6556e62afc509179")');

	if ($id != '') {
		// Remove Data Sources and Graphs
		$data_sources = array_rekey(
			db_fetch_assoc('SELECT DISTINCT local_data_id
				FROM data_template_data
				WHERE local_data_id > 0
				AND data_input_id IN(' . $id . ')'),
			'local_data_id', 'local_data_id'
		);

		if (sizeof($data_sources)) {
			cacti_log('NOTE: Found ' . sizeof($data_sources) . ' WMI Data Sources during uninstall, deleting!', false, 'WMI');

			$graphs = array_rekey(
				db_fetch_assoc('SELECT
					graph_templates_graph.local_graph_id
					FROM (data_template_rrd,graph_templates_item,graph_templates_graph)
					WHERE graph_templates_item.task_item_id=data_template_rrd.id
					AND graph_templates_item.local_graph_id=graph_templates_graph.local_graph_id
					AND ' . array_to_sql_or($data_sources, 'data_template_rrd.local_data_id') . '
					AND graph_templates_graph.local_graph_id > 0
					GROUP BY graph_templates_graph.local_graph_id'),
				'local_graph_id', 'local_graph_id'
			);

			cacti_log('NOTE: Found ' . sizeof($graphs) . ' WMI Data Sources during uninstall, deleting!', false, 'WMI');

			if (sizeof($graphs)) {
				api_graph_remove_multi($graphs);
			}

			api_plugin_hook_function('graphs_remove', $graphs);

			api_data_source_remove_multi($data_sources);
		}

		// Remove any templates
	}
}

function plugin_wmi_check_config() {
	return true;
}

function plugin_wmi_upgrade() {
	return true;
}

function plugin_wmi_setup_tables() {
	db_execute("CREATE TABLE IF NOT EXISTS `wmi_user_accounts` (
		`id` int(11) UNSIGNED NOT NULL auto_increment,
		`name` varchar(64) NOT NULL,
		`username` varchar(64) NOT NULL,
		`password` varchar(256) NOT NULL,
		PRIMARY KEY (`id`))
		ENGINE=InnoDB
		COMMENT='Holds Account Information for WMI Queries'");

	db_execute("CREATE TABLE IF NOT EXISTS `wmi_wql_queries` (
		`id` int(11) UNSIGNED NOT NULL auto_increment,
		`hash` varchar(32) NOT NULL default '',
		`name` varchar(64) NOT NULL,
		`frequency` mediumint(8) unsigned NOT NULL DEFAULT '86400',
		`enabled` char(2) DEFAULT 'on',
		`namespace` varchar(64) NOT NULL,
		`query` varchar(1024) NOT NULL,
		`primary_key` varchar(128) NOT NULL DEFAULT 'None',
		PRIMARY KEY (`id`))
		ENGINE=InnoDB
		COMMENT='Holds WMI Queries for Devices'");

	db_execute("CREATE TABLE IF NOT EXISTS `host_wmi_query` (
		`host_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`wmi_query_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`sort_field` varchar(50) NOT NULL DEFAULT '',
		`title_format` varchar(50) NOT NULL DEFAULT '',
		PRIMARY KEY (`host_id`,`wmi_query_id`))
		ENGINE=InnoDB
		COMMENT='Holds WMI Data Queries'");

	db_execute("CREATE TABLE IF NOT EXISTS `host_template_wmi_query` (
		`host_template_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`wmi_query_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		PRIMARY KEY (`host_template_id`,`wmi_query_id`))
		ENGINE=InnoDB
		COMMENT='Holds Device Template WMI Queries'");

	db_execute("CREATE TABLE IF NOT EXISTS `host_wmi_accounts` (
		`id` int(11) UNSIGNED NOT NULL auto_increment,
		`host_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`account_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		PRIMARY KEY (`id`),
		KEY `host_id` (`host_id`))
		ENGINE=InnoDB
		COMMENT='Holds Device WMI Accounts'");

	db_execute("CREATE TABLE IF NOT EXISTS `host_wmi_cache` (
		`host_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`wmi_query_id` mediumint(8) unsigned NOT NULL DEFAULT '0',
		`field_name` varchar(50) NOT NULL DEFAULT '',
		`field_value` varchar(512) DEFAULT NULL,
		`wmi_index` varchar(255) NOT NULL DEFAULT '',
		`present` tinyint(4) NOT NULL DEFAULT '1',
		`last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (`host_id`,`wmi_query_id`,`field_name`,`wmi_index`),
		KEY `host_id` (`host_id`,`field_name`),
		KEY `wmi_index` (`wmi_index`),
		KEY `field_name` (`field_name`),
		KEY `field_value` (`field_value`),
		KEY `wim_query_id` (`wmi_query_id`),
		KEY `present` (`present`),
		KEY `last_updated` (`last_updated`))
		ENGINE=InnoDB
		COMMENT='Holds Device WMI Information'");

	$exists = db_fetch_cell('SELECT id FROM data_input_data WHERE hash="4af550dfe8b451579054d038ad62ba3e"');
	if (!$exists) {
		$save = array();
		$save['hash']         = '4af550dfe8b451579054d038ad62ba3e';
		$save['name']         = 'Get WMI Data';
		$save['input_string'] = '';
		$save['type_id']      = 7;
		$id = sql_save('data_input', $save);

		if ($id) {
			db_execute("INSERT INTO `data_input_fields`
				(hash, name, input_string, type_id)
				VALUES ('e45cfa73589b88887725350a728d2ee9',$id,'The WMI Class Name','class','in','',0,'','','')");

			db_execute("INSERT INTO `data_input_fields`
				(hash, name, input_string, type_id)
				VALUES ('c5f782d783edec607f64bea9cccd533c',$id,'The WMI Column Name','column','in','',0,'','','')");
		}
	}

	$exists = db_fetch_cell('SELECT id FROM data_input_data WHERE hash="42e584b81075f6ad6556e62afc509179"');
	if (!$exists) {
		$save = array();
		$save['hash']         = '42e584b81075f6ad6556e62afc509179';
		$save['name']         = 'Get WMI Data (Indexed)';
		$save['input_string'] = '';
		$save['type_id']      = 8;
		$id = sql_save('data_input', $save);

		if ($id) {
			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES (96,'fb6317f2c49e494007e968283576d5a8',$id,'The WMI Class Name','class','in','',0,'','','')");

			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES (97,'cfebf9aa08f98bc1bfda7de2ebe12d94',$id,'The WMI Column Name','column','in','',0,'','','')");

			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES (98,'41798400f48141c25bc2407b5f5b1573',$id,'Output Type ID','output_type','in','',0,'output_type','','')");

			db_execute("INSERT INTO `data_input_fields`
				(hash, data_input_id, name, data_name, input_output, update_rra, sequence, type_code, regexp_match, allow_nulls)
				VALUES (99,'02cd18a75a17e0a7d4ca28bc224630e0',$id,'Output Value','output','out','on',0,'','','')");
		}
	}

	//	db_execute("INSERT INTO `wmi_wql_queries` (`id`, `name`, `queryname`, `indexkey`, `querykeys`, `queryclass`) VALUES (1, 'Exchange Messages', 'exchangemessages', 'Name', 'MessagesDelivered,MessagesSent', 'Win32_PerfRawData_MSExchangeIS_MSExchangeISMailbox')");
}

function plugin_wmi_version() {
	global $config;

	$info = parse_ini_file($config['base_path'] . '/plugins/wmi/INFO', true);
	return $info['info'];
}

function wmi_poller_bottom() {
	global $config;

    /* graph export */
	if ($config['poller_id'] == 1) {
		$command_string = read_config_option('path_php_binary');
		$extra_args = '-q "' . $config['base_path'] . '/plugins/wmi/poller_wmi.php"';
		exec_background($command_string, $extra_args);
	}
}

function wmi_config_arrays() {
	global $user_auth_realms, $user_auth_realm_filenames, $menu;
	global $input_types, $fields_data_query_edit, $wmi_frequencies;

	if (!defined('DATA_INPUT_TYPE_WMI')) {
		define('DATA_INPUT_TYPE_WMI', 7);
	}

	if (!defined('DATA_INPUT_TYPE_WMI_QUERY')) {
		define('DATA_INPUT_TYPE_WMI_QUERY', 8);
	}

	$input_types += array(
		DATA_INPUT_TYPE_WMI => __('WMI Data'),
		DATA_INPUT_TYPE_WMI_QUERY => __('WMI Data Query')
	);

	$wmi_frequencies = array(
		'60'    => __('%d Minute',  1),
		'120'   => __('%d Minutes', 2),
		'300'   => __('%d Minutes', 5),
		'600'   => __('%d Minutes', 10),
		'1200'  => __('%d Minutes', 20),
		'2400'  => __('%d Minutes', 40),
		'3600'  => __('%d Hour',   1),
		'7200'  => __('%d Hours',  2),
		'14400' => __('%d Hours', 4),
		'86400' => __('%d Day', 1)
	);

	$fields_data_query_edit['data_input_id']['sql'] = 'SELECT id,name FROM data_input WHERE type_id IN(3,4,6,8) ORDER BY name';

	$menu[__('Utilities')]['plugins/wmi/wmi_tools.php']      = __('WMI Query Tool');
	$menu[__('Data Collection')]['plugins/wmi/wmi_queries.php'] = __('WMI Queries');
}

function wmi_data_input_sql_where($sql_where) {
	// Exclude special data input methods
    $sql_where .= (strlen($sql_where) ? ' AND':'WHERE') . " (di.hash NOT IN ('4af550dfe8b451579054d038ad62ba3e', '42e584b81075f6ad6556e62afc509179'))";

	return $sql_where;
}

function wmi_draw_navigation_text($nav) {
	$nav['wmi_accounts.php:']        = array(
		'title' => __('WMI Autenication'),
		'mapping' => 'index.php:',
		'url' => 'wmi_accounts.php',
		'level' => '1'
	);

	$nav['wmi_accounts.php:edit'] = array(
		'title' => __('WMI Autenication -> Edit'),
		'mapping' => 'index.php:',
		'url' => 'wmi_accounts.php',
		'level' => '1'
	);

	$nav['wmi_accounts.php:actions'] = array(
		'title' => __('WMI Autenication'),
		'mapping' => 'index.php:',
		'url' => 'wmi_accounts.php',
		'level' => '1'
	);

	$nav['wmi_queries.php:'] = array(
		'title' => __('WMI Queries'),
		'mapping' => 'index.php:',
		'url' => 'wmi_queries.php',
		'level' => '1'
	);

	$nav['wmi_queries.php:edit'] = array(
		'title' => __('WMI Queries -> Edit'),
		'mapping' => 'index.php:',
		'url' => 'wmi_queries.php',
		'level' => '2'
	);

	$nav['wmi_queries.php:actions'] = array(
		'title' => __('WMI Queries'),
		'mapping' => 'index.php:',
		'url' => 'wmi_queries.php',
		'level' => '2'
	);

	$nav['wmi_tools.php:'] = array(
		'title' => ('WMI Tools'),
		'mapping' => 'index.php:',
		'url' => 'wmi_tools.php',
		'level' => '1'
	);

	$nav['wmi_tools.php:query'] = array(
		'title' => __('WMI Tools'),
		'mapping' => 'index.php:',
		'url' => 'wmi_tools.php',
		'level' => '1'
	);

	return $nav;
}

function wmi_config_form() {
	global $fields_host_edit, $plugins;

//	$fields_host_edit2 = $fields_host_edit;
//	$fields_host_edit3 = array();
//	foreach ($fields_host_edit2 as $f => $a) {
//		if ($f == 'disabled') {
//			$fields_host_edit3['serial'] = array(
//				'friendly_name' => 'Serial / Service Code',
//				'description' => 'This is the Serial Number for this server.',
//				'method' => 'textbox',
//				'max_length' => 100,
//				'value' => '|arg1:serial|',
//				'default' => '',
//			);
//		}
//		$fields_host_edit3[$f] = $a;
//	}
//	$fields_host_edit = $fields_host_edit3;

	$acc = array('None');
	$accounts = db_fetch_assoc('SELECT id, name FROM wmi_user_accounts ORDER BY name', false);
	if (!empty($accounts)) {
		foreach ($accounts as $a) {
			$acc[$a['id']] = $a['name'];
		}
	}

	$fields_host_edit['wmi_spacer'] = array(
		'method' => 'spacer',
		'friendly_name' => __('WMI Account Options')
	);

	$fields_host_edit['wmi_account'] = array(
		'method' => 'drop_array',
		'friendly_name' => __('WMI Authentication Account'),
		'description' => __('Choose an account to use when Authenticating via WMI'),
		'value' => '|arg1:wmi_account|',
		'default' => 0,
		'array' => $acc,
	);
}

function wmi_config_settings () {
	global $tabs, $settings, $item_rows, $config;

	if (get_current_page() != 'settings.php') return;

	$settings['snmp'] = array_merge($settings['snmp'], array(
		'wmi_general_header' => array(
			'friendly_name' => __('WMI Settings'),
			'method' => 'spacer',
		),
		'wmi_autocreate' => array(
			'friendly_name' => __('Auto Create WMI Queries'),
			'description' => __('If selected, when running either automation, or when creating/saving a Device, all WMI Queries associated with the Device Template will be created.'),
			'method' => 'checkbox',
			'default' => 'on'
		),
	));
}

function wmi_api_device_save($save) {
	if (isset_request_var('wmi_account')) {
		$save['wmi_account'] = form_input_validate(get_filter_request_var('wmi_account'), 'wmi_account', '^[0-9]+$', false, 3);
	} else {
		$save['wmi_account'] = 0;
	}

	return $save;
}

function wmi_device_edit_pre_bottom() {
	html_start_box(__('Associated WMI Queries'), '100%', '', '3', 'center', '');

	$host_template_id = db_fetch_cell_prepared('SELECT host_template_id FROM host WHERE id = ?' ,array(get_request_var('id')));

	$wmi_queries = db_fetch_assoc_prepared('SELECT wwq.id, wwq.name
		FROM wmi_wql_queries AS wwq
		LEFT JOIN host_template_wmi_query AS htwq
		ON wwq.id=htwq.wmi_query_id
		WHERE htwq.host_template_id = ? ORDER BY name', array($host_template_id));

	html_header(array(__('Name'), __('Status')));

	$i = 1;
	if (sizeof($wmi_queries)) {
		foreach ($wmi_queries as $item) {
			$exists = db_fetch_cell_prepared('SELECT wmi_query_id
				FROM host_wmi_query
				WHERE host_id = ?
				AND wmi_query_id = ?',
				array(get_request_var('id'), $item['id']));

			if ($exists) {
				$exists = __('WMI Query Exists');
			}else{
				$exists = __('WMI Query Does Not Exist');
			}

			form_alternate_row("wq$i", true);
			?>
				<td class='left'>
					<strong><?php print $i;?>)</strong> <?php print htmlspecialchars($item['name']);?>
				</td>
				<td>
					<?php print $exists;?>
				</td>
			<?php
			form_end_row();

			$i++;
		}
	}else{
		print '<tr><td colspan="2"><em>' . __('No Associated WMI Queries.') . '</em></td></tr>';
	}

	html_end_box();
}

function wmi_device_template_edit() {
	html_start_box(__('Associated WMI Queries'), '100%', '', '3', 'center', '');

	$wmi_queries = db_fetch_assoc_prepared('SELECT wwq.id, wwq.name
		FROM wmi_wql_queries AS wwq
		INNER JOIN host_template_wmi_query AS htwq
		ON wwq.id=htwq.wmi_query_id
		WHERE htwq.host_template_id = ? ORDER BY name', array(get_request_var('id')));

	$i = 1;
	if (sizeof($wmi_queries)) {
		foreach ($wmi_queries as $item) {
			form_alternate_row("wq$i", true);
			?>
				<td class='left'>
					<strong><?php print $i;?>)</strong> <?php print htmlspecialchars($item['name']);?>
				</td>
				<td class='right'>
					<a class='delete deleteMarker fa fa-remove' title='<?php print __('Delete');?>' href='<?php print htmlspecialchars('host_templates.php?action=item_remove_wq_confirm&id=' . $item['id'] . '&host_template_id=' . get_request_var('id'));?>'></a>
				</td>
			<?php
			form_end_row();

			$i++;
		}
	}else{
		print '<tr><td colspan="2"><em>' . __('No Associated WMI Queries.') . '</em></td></tr>';
	}

	$unmapped = db_fetch_assoc_prepared('SELECT DISTINCT wwq.id, wwq.name
		FROM wmi_wql_queries AS wwq
		LEFT JOIN host_template_wmi_query AS htwq
		ON wwq.id=htwq.wmi_query_id
		WHERE htwq.host_template_id IS NULL OR htwq.host_template_id != ?
		ORDER BY wwq.name', array(get_request_var('id')));

	if (sizeof($unmapped)) {
		?>
		<tr class='odd'>
			<td colspan='2'>
				<table>
					<tr style='line-height:10px;'>
						<td style='padding-right: 15px;'>
							<?php print __('Add WMI Query');?>
						</td>
						<td>
							<?php form_dropdown('wmi_query_id',$unmapped ,'name','id','','','');?>
						</td>
						<td>
							<input type='button' value='<?php print __('Add');?>' id='add_wq' title='<?php print __('Add WMI Query to Device Template');?>'>
						</td>
					</tr>
				</table>
				<script type='text/javascript'>
				$('#add_wq').click(function() {
					$.post('host_templates.php?header=false&action=item_add_wq', {
						host_template_id: $('#id').val(),
						wmi_query_id: $('#wmi_query_id').val(),
						__csrf_magic: csrfMagicToken
					}).done(function(data) {
						$('div[class^="ui-"]').remove();
						$('#main').html(data);
						applySkin();
					});
				});
				</script>
			</td>
		</tr>
		<?php
	}

	html_end_box();
}

function wmi_device_template_top() {
	if (get_request_var('action') == 'item_remove_wq_confirm') {
		/* ================= input validation ================= */
		get_filter_request_var('id');
		get_filter_request_var('host_template_id');
		/* ==================================================== */

		form_start('host_templates.php?action=edit&id' . get_request_var('host_template_id'));

		html_start_box('', '100%', '', '3', 'center', '');

		$query = db_fetch_row_prepared('SELECT * FROM wmi_wql_queries WHERE id = ?', array(get_request_var('id')));

		?>
		<tr>
			<td class='topBoxAlt'>
				<p><?php print __('Click \'Continue\' to delete the following WMI Queries will be disassociated from the Device Template.');?></p>
				<p><?php print __('WMI Query Name: %s', htmlspecialchars($query['name']));?>'<br>
			</td>
		</tr>
		<tr>
			<td align='right'>
				<input id='cancel' type='button' value='<?php print __('Cancel');?>' onClick='$("#cdialog").dialog("close")' name='cancel'>
				<input id='continue' type='button' value='<?php print __('Continue');?>' name='continue' title='<?php print __('Remove WMI Query');?>'>
			</td>
		</tr>
		<?php

		html_end_box();

		form_end();

		?>
		<script type='text/javascript'>
		$(function() {
			$('#cdialog').dialog();
		});

	    $('#continue').click(function(data) {
			$.post('host_templates.php?action=item_remove_wq', {
				__csrf_magic: csrfMagicToken,
				host_template_id: <?php print get_request_var('host_template_id');?>,
				id: <?php print get_request_var('id');?>
			}, function(data) {
				$('#cdialog').dialog('close');
				loadPageNoHeader('host_templates.php?action=edit&header=false&id=<?php print get_request_var('host_template_id');?>');
			});
		});
		</script>
		<?php

		exit;
	}elseif (get_request_var('action') == 'item_remove_wq') {
		/* ================= input validation ================= */
		get_filter_request_var('id');
		get_filter_request_var('host_template_id');
		/* ==================================================== */

		db_execute_prepared('DELETE FROM host_template_wmi_query WHERE wmi_query_id = ? AND host_template_id = ?', array(get_request_var('id'), get_request_var('host_template_id')));

		header('Location: host_templates.php?header=false&action=edit&id=' . get_request_var('host_template_id'));

		exit;
	}elseif (get_request_var('action') == 'item_add_wq') {
		/* ================= input validation ================= */
		get_filter_request_var('host_template_id');
		get_filter_request_var('wmi_query_id');
		/* ==================================================== */

		db_execute_prepared('REPLACE INTO host_template_wmi_query
			(host_template_id, wmi_query_id) VALUES (?, ?)',
			array(get_request_var('host_template_id'), get_request_var('wmi_query_id')));

		header('Location: host_templates.php?header=false&action=edit&id=' . get_request_var('host_template_id'));

		exit;
	}
}

function wmi_api_device_new($save) {
	global $config;

	include_once($config['base_path'] . '/plugins/wmi/functions.php');

	if (read_config_option('wmi_autocreate') == 'on') {
		if (!empty($save['id'])) {
			//wmi_autocreate($save['id']);
		}
	}

	return $save;
}

