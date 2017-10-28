<?php

require_once 'casedatecascade.civix.php';
use CRM_Casedatecascade_ExtensionUtil as E;

/**
 * Implements hook_civicrm_pre().
 *
 * If Activity is being edited, remember whether the edit includes the date.
 */
function casedatecascade_civicrm_pre($op, $objectName, $id, &$params) {
  // use cache?
  // don't load anything from db
  // will this hook catch every date edit, including ones made in other extensions' hook functions?
}

/**
 * Implements hook_civicrm_post().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_post/
 *
 * Catch case-related Activity objects which have just been edited, and pass
 * them on for further processing.
 */
function casedatecascade_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($objectName == 'Activity' && !empty($objectRef->case_id)) {
    // TODO: clean up ordinal cache at this line?
    if ($op == 'edit') {
      _casedatecascade_process_potential_reference_activity($objectRef);
    }
  }
}

/**
 * Determine the type of the given case-related activity; determine whether
 * activities of that type are used as reference activities in the case's
 * timelines. If so, initiate further processing.
 *
 * @param CRM_Activity_DAO_Activity $subject
 *   The activity object that has just been edited
 */
function _casedatecascade_process_potential_reference_activity(&$subject) {
  $subject_id = $subject->id;
  $case_id = $subject->case_id;

  // TODO: error handling
  // TODO: caching
  $case_result = civicrm_api3('Case', 'get', array(
    'id' => $case_id,
    'return' => array('case_type_id.definition'),
    'sequential' => 1,
  ));

  $activity_result = civicrm_api3('Activity', 'get', array(
    'return' => array('activity_type_id.name', 'activity_date_time', 'status_id.name'),
    'case_id' => $case_id,
  ));
  $case_activities = $activity_result['values'];

  $subject_type = $case_activities[$subject_id]['activity_type_id.name'];
  // since an activity of this type has just been modified, the ordinal cache
  // may be out of date
  // TODO: also cleanup cache when activities are added to/deleted from the case
  // which might mean changing how the cache item is keyed and moving this next 
  // line to casedatecascade_civicrm_post
  Civi::cache()->delete('casedatecascade' . $case_id . '|' . $subject_type);

  $activity_sets = $case_result['values'][0]['case_type_id.definition']['activitySets'];
  $dependant_activity_types = array();

  foreach ($activity_sets as $activity_set) {
    if (!CRM_Utils_Array::value('timeline', $activity_set)) {
      continue;
    }
    foreach ($activity_set['activityTypes'] as $timeline_item) {
      $timeline_item_ref_type = CRM_Utils_Array::value('reference_activity', $timeline_item);
      if ($timeline_item_ref_type == $subject_type) {
        $dependant_activity_types[$timeline_item['name']] = $timeline_item;
      }
    }
  }

  if ($dependant_activity_types) {
    _casedatecascade_cascade($subject, $subject_type, $dependant_activity_types, $case_activities);
  }
  // TODO: remove debug
  CRM_Core_Error::debug_var('case_activities', $case_activities, $print = TRUE, $log = TRUE);
}

/**
 * Given a just-edited activity, adjust the dates of dependant activities as
 * needed.
 *
 * @param CRM_Activity_DAO_Activity $reference_activity
 *   The activity object that has just been edited
 * @param array $dependant_activity_types
 *   Names of activity types that depend on the reference activity type
 * @param array $case_activities
 *   All activities associated with the case
 */
function _casedatecascade_cascade(&$reference_activity, $dependant_activity_types, $case_activities) {
  $reference_activity_date = date_create($reference_activity->activity_date_time);
  foreach ($case_activities as $activity_id => $activity) {
    $activity_type_name = $activity['activity_type_id.name'];

    // we're only interested in activities that depend on the reference activity
    if (!array_key_exists($activity_type_name, $dependant_activity_types)) {
      continue;
    }

    // TODO: we're not interested in "Completed" activities

    $dependent_activity_type = $dependant_activity_types[$activity_type_name];

    // make sure the reference activity is the newest or oldest, if specified
    $reference_select = CRM_Utils_Array::value('reference_select', $dependent_activity_type);
    if ($reference_select) {
      if (!isset($oldest)) {
        list($oldest, $newest) = _casedatecascade_get_newest_and_oldest(
          $reference_activity,
          $case_activities);
      }
      if ($reference_activity->id != $$reference_select) {
        continue;
      }
    }

    // now actually calculate the new 'activity_date_time' for the $activity
    $activity_date = new DateTime($activity['activity_date_time']);
    $offset_days = CRM_Utils_Array::value('reference_offset', $dependent_activity_type, 0);
    $activity_date->add(new DateInterval('P' . $offset_days . 'D'));

    // TODO: error handling?
    $save_result = civicrm_api3('Activity', 'create', array(
      'id' => $activity_id,
      'activity_date_time' => $date->format('Y-m-d H:i:s'),
    ));
  }
}

/**
 * Find the oldest and newest activities of a certain type in a case.
 *
 * @param CRM_Activity_DAO_Activity $reference_activity
 *   Determines which type we look for.
 * @param array $case_activities
 *   Array of arrays: top level keys are activity ids, second level keys
 *   include 'activity_type_id.name' and 'activity_date_time'
 */
function _casedatecascade_get_newest_and_oldest(&$reference_activity, $case_activities) {
  $case_id = $reference_activity->case_id;
  $reference_activity_type = $case_activities[$reference_activity->id]['activity_type_id.name'];
  $cached_val = Civi::cache()->get('casedatecascade' . $case_id . '|' . $reference_activity_type);
  list($oldest, $newest) = $cached_val ? $cached_val : array(NULL, NULL);

  if ($cached_val === NULL) {
    $min_date_time = $max_date_time = NULL;

    foreach ($case_activities as $activity_id => $activity) {
      if ($activity['activity_type_id.name'] == $reference_activity_type) {

        $activity_date_time = strtotime($activity['activity_date_time']);

        if (!isset($min_date_time)) {
          $min_date_time = $max_date_time = $activity_date_time;
          $oldest = $newest = $activity_id;
        } elseif ($activity_date_time < $min_date_time) {
          $min_date_time = $activity_date_time;
          $oldest = $activity_id;
        } elseif ($activity_date_time > $min_date_time) {
          $max_date_time = $activity_date_time;
          $newest = $activity_id;
        }

      }
    }
    Civi::cache()->set('casedatecascade' . $case_id . '|' . $reference_activity_type);
  }

  return array($oldest, $newest);
}

/**
 * CIVIX-GENERATED CODE
 */

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function casedatecascade_civicrm_config(&$config) {
  _casedatecascade_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function casedatecascade_civicrm_xmlMenu(&$files) {
  _casedatecascade_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function casedatecascade_civicrm_install() {
  _casedatecascade_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function casedatecascade_civicrm_postInstall() {
  _casedatecascade_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function casedatecascade_civicrm_uninstall() {
  _casedatecascade_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function casedatecascade_civicrm_enable() {
  _casedatecascade_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function casedatecascade_civicrm_disable() {
  _casedatecascade_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function casedatecascade_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _casedatecascade_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function casedatecascade_civicrm_managed(&$entities) {
  _casedatecascade_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function casedatecascade_civicrm_caseTypes(&$caseTypes) {
  _casedatecascade_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function casedatecascade_civicrm_angularModules(&$angularModules) {
  _casedatecascade_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function casedatecascade_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _casedatecascade_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function casedatecascade_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function casedatecascade_civicrm_navigationMenu(&$menu) {
_casedatecascade_civix_insert_navigation_menu($menu, NULL, array(
'label' => E::ts('The Page'),
'name' => 'the_page',
'url' => 'civicrm/the-page',
'permission' => 'access CiviReport,access CiviContribute',
'operator' => 'OR',
'separator' => 0,
));
_casedatecascade_civix_navigationMenu($menu);
} // */
