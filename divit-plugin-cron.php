<?php

class Divit_Plugin_Cron
{
  public static function edit_cron_schedules($schedules) {
		$schedules['15min'] = array(
			'interval' => 15 * 60,
			'display' => __( 'Every 15 minutes', 'woo_divit' )
		);
		return $schedules;
	}

  public static function create_jobs() {
		$timestamp = wp_next_scheduled( 'divit_do_cron_jobs' );
		if ($timestamp == false) {
			wp_schedule_event( time(), '15min', 'divit_do_cron_jobs' );
		}
	}

  public static function delete_jobs() {
		wp_clear_scheduled_hook( 'divit_do_cron_jobs' );
	}

  public static function fire_jobs() {
		if (defined('DOING_CRON') && DOING_CRON === true) {
			$fired_by = 'schedule';
		} elseif (is_admin()) {
			$fired_by = 'admin';
		} else {
			$fired_by = 'unknown';
		}
		WC_Divit_Payment_Gateway::log("Firing cron by {$fired_by}...");
	}
}