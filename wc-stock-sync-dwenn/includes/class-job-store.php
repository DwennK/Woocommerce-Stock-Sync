<?php

if (!defined('ABSPATH')) exit;

class WCSSD_JobStore {
  private $ttl;

  public function __construct(int $ttl) {
    $this->ttl = $ttl;
  }

  public function current_user_id_safe() {
    $uid = get_current_user_id();
    return $uid ? (int)$uid : 0;
  }

  public function job_key($job_id) {
    return 'wcssd_job_' . sanitize_key($job_id);
  }

  public function last_job_meta_key() {
    return 'wcssd_last_job_id';
  }

  public function make_job_id() {
    return wp_generate_password(20, false, false);
  }

  public function get($job_id) {
    return get_transient($this->job_key($job_id));
  }

  public function set($job_id, array $job) {
    return set_transient($this->job_key($job_id), $job, $this->ttl);
  }

  public function delete($job_id) {
    return delete_transient($this->job_key($job_id));
  }

  public function remember_for_current_user($job_id) {
    $uid = $this->current_user_id_safe();
    if ($uid) {
      update_user_meta($uid, $this->last_job_meta_key(), $job_id);
    }
  }

  public function clear_for_current_user() {
    $uid = $this->current_user_id_safe();
    if ($uid) {
      delete_user_meta($uid, $this->last_job_meta_key());
    }
  }

  public function get_resume_job_id() {
    $uid = $this->current_user_id_safe();
    $last_job_id = $uid ? get_user_meta($uid, $this->last_job_meta_key(), true) : '';

    if (!$last_job_id) {
      return '';
    }

    $job = $this->get($last_job_id);
    if (is_array($job) && !empty($job['job_id'])) {
      return $job['job_id'];
    }

    return '';
  }

  public function current_user_can_access_job(array $job) {
    $uid = $this->current_user_id_safe();
    if (!$uid) {
      return false;
    }

    if (!empty($job['owner_user_id'])) {
      return (int)$job['owner_user_id'] === $uid;
    }

    $last_job_id = get_user_meta($uid, $this->last_job_meta_key(), true);
    return !empty($job['job_id']) && $last_job_id === $job['job_id'];
  }
}
