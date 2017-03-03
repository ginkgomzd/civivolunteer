<?php

class CRM_Volunteer_BAO_NeedSearch {

  /**
   * @var array
   *   Holds project data for the Needs matched by the search. Keyed by project ID.
   */
  private $projects = array();

  /**
   * @var array
   *   See  getDefaultSearchParams() for format.
   */
  private $searchParams = array();

  /**
   * @var array
   *   An array of needs. The results of the search, which will ultimately be returned.
   */
  private $searchResults = array();

  /**
   * @param array $userSearchParams
   *   See setSearchParams();
   */
  public function __construct ($userSearchParams) {
    $this->searchParams = $this->getDefaultSearchParams();
    $this->setSearchParams($userSearchParams);
  }

  /**
   * Convenience static method for searching without instantiating the class.
   *
   * Invoked from the API layer.
   *
   * @param array $userSearchParams
   *   See setSearchParams();
   * @return array $this->searchResults
   */
  public static function doSearch ($userSearchParams) {
    $searcher = new self($userSearchParams);
    return $searcher->search();
  }

  /**
   * @return array
   *   Used as the starting point for $this->searchParams.
   */
  private function getDefaultSearchParams() {
    return array(
      'project' => array(
        'is_active' => 1,
      ),
      'need' => array(
        'role_id' => array(),
      ),
    );
  }

  /**
   * Performs the search.
   *
   * Stashes the results in $this->searchResults.
   *
   * @return array $this->searchResults
   */
  public function search() {
    $projects = CRM_Volunteer_BAO_Project::retrieve($this->searchParams['project']);
    foreach ($projects as $project) {
      $results = array();

      $flexibleNeed = civicrm_api3('VolunteerNeed', 'getsingle', array(
        'id' => $project->flexible_need_id,
      ));
      if ($flexibleNeed['visibility_id'] === CRM_Core_OptionGroup::getValue('visibility', 'public', 'name')) {
        $needId = $flexibleNeed['id'];
        $results[$needId] = $flexibleNeed;
      }

      $openNeeds = $project->open_needs;
      foreach ($openNeeds as $key => $need) {
        if ($this->needFitsSearchCriteria($need)) {
          $results[$key] = $need;
        }
      }

      if (!empty($results)) {
        $this->projects[$project->id] = array();
      }

      $this->searchResults += $results;
    }

    $this->getSearchResultsProjectData();
    usort($this->searchResults, array($this, "usortDateAscending"));
    return $this->searchResults;
  }

  /**
   * Returns TRUE if the need matches the dates in the search criteria, else FALSE.
   *
   * Assumptions:
   *   - Need start_time is never empty. (Only in exceptional cases should this
   *     assumption be false for non-flexible needs. Flexible needs are excluded
   *     from $project->open_needs.)
   *
   * @param array $need
   * @return boolean
   */
  private function needFitsDateCriteria(array $need) {
    $needStartTime = strtotime(CRM_Utils_Array::value('start_time', $need));
    $needEndTime = strtotime(CRM_Utils_Array::value('end_time', $need));

    // There are no date-related search criteria, so we're done here.
    if ($this->searchParams['need']['date_start'] === FALSE && $this->searchParams['need']['date_end'] === FALSE) {
      return TRUE;
    }

    // The search window has no end time. We need to verify only that the need
    // has dates after the start time.
    if ($this->searchParams['need']['date_end'] === FALSE) {
      return $needStartTime >= $this->searchParams['need']['date_start'] || $needEndTime >= $this->searchParams['need']['date_start'];
    }

    // The search window has no start time. We need to verify only that the need
    // starts before the end of the window.
    if ($this->searchParams['need']['date_start'] === FALSE) {
      return $needStartTime <= $this->searchParams['need']['date_end'];
    }

    // The need does not have fuzzy dates, and both ends of the search
    // window have been specified. We need to verify only that the need
    // starts in the search window.
    if ($needEndTime === FALSE) {
      return $needStartTime >= $this->searchParams['need']['date_start'] && $needStartTime <= $this->searchParams['need']['date_end'];
    }

    // The need has fuzzy dates, and both endpoints of the search window were
    // specified:
    return
      // Does the need start in the provided window...
      ($needStartTime >= $this->searchParams['need']['date_start'] && $needStartTime <= $this->searchParams['need']['date_end'])
      // or does the need end in the provided window...
      || ($needEndTime >= $this->searchParams['need']['date_start'] && $needEndTime <= $this->searchParams['need']['date_end'])
      // or are the endpoints of the need outside the provided window?
      || ($needStartTime <= $this->searchParams['need']['date_start'] && $needEndTime >= $this->searchParams['need']['date_end']);
  }

  /**
   * @param array $need
   * @return boolean
   */
  private function needFitsSearchCriteria(array $need) {
    return
      $this->needFitsDateCriteria($need)
      && (
        // Either no role was specified in the search...
        empty($this->searchParams['need']['role_id'])
        // or the need role is in the list of searched-by roles.
        || in_array($need['role_id'], $this->searchParams['need']['role_id'])
      );
  }

  /**
   * @param array $userSearchParams
   *   Supported parameters:
   *     - beneficiary: mixed - an int-like string, a comma-separated list
   *         thereof, or an array representing one or more contact IDs
   *     - project: int-like string representing project ID
   *     - proximity: array - see CRM_Volunteer_BAO_Project::buildProximityWhere
   *     - role_id: mixed - an int-like string, a comma-separated list thereof, or
   *         an array representing one or more role IDs
   *     - date_start: See setSearchDateParams()
   *     - date_end: See setSearchDateParams()
   */
  private function setSearchParams($userSearchParams) {
    $this->setSearchDateParams($userSearchParams);

    $projectId = CRM_Utils_Array::value('project', $userSearchParams);
    if (CRM_Utils_Type::validate($projectId, 'Positive', FALSE)) {
      $this->searchParams['project']['id'] = $projectId;
    }

    $proximity = CRM_Utils_Array::value('proximity', $userSearchParams);
    if (is_array($proximity)) {
      $this->searchParams['project']['proximity'] = $proximity;
    }

    $beneficiary = CRM_Utils_Array::value('beneficiary', $userSearchParams);
    if ($beneficiary) {
      if (!array_key_exists('project_contacts', $this->searchParams['project'])) {
        $this->searchParams['project']['project_contacts'] = array();
      }
      $beneficiary = is_array($beneficiary) ? $beneficiary : explode(',', $beneficiary);
      $this->searchParams['project']['project_contacts']['volunteer_beneficiary'] = $beneficiary;
    }

    $role = CRM_Utils_Array::value('role_id', $userSearchParams);
    if ($role) {
      $this->searchParams['need']['role_id'] = is_array($role) ? $role : explode(',', $role);
    }
  }

  /**
   * Sets date_start and date_need in $this->searchParams to a timestamp or to
   * boolean FALSE if invalid values were supplied.
   *
   * @param array $userSearchParams
   *   Supported parameters:
   *     - date_start: date
   *     - date_end: date
   */
  private function setSearchDateParams($userSearchParams) {
    $this->searchParams['need']['date_start'] = strtotime(CRM_Utils_Array::value('date_start', $userSearchParams));
    $this->searchParams['need']['date_end'] = strtotime(CRM_Utils_Array::value('date_end', $userSearchParams));
  }

  /**
   * Adds 'project' key to each need in $this->searchResults, containing data
   * related to the project, campaign, location, and project contacts.
   */
  private function getSearchResultsProjectData() {
    // api.VolunteerProject.get does not support the 'IN' operator, so we loop
    foreach ($this->projects as $id => &$project) {
      $api = civicrm_api3('VolunteerProject', 'getsingle', array(
        'id' => $id,
        'api.Campaign.getvalue' => array(
          'return' => 'title',
        ),
        'api.LocBlock.getsingle' => array(
          'api.Address.getsingle' => array(),
        ),
        'api.VolunteerProjectContact.get' => array(
          'options' => array('limit' => 0),
          'relationship_type_id' => 'volunteer_beneficiary',
          'api.Contact.get' => array(
            'options' => array('limit' => 0),
          ),
        ),
      ));

      $project['description'] = $api['description'];
      $project['id'] = $api['id'];
      $project['title'] = $api['title'];

      // Because of CRM-17327, the chained "get" may improperly report its result,
      // so we check the value we're chaining off of to decide whether or not
      // to trust the result.
      $project['campaign_title'] = empty($api['campaign_id']) ? NULL : $api['api.Campaign.getvalue'];

      // CRM-17327
      if (empty($api['loc_block_id']) || empty($api['api.LocBlock.getsingle']['address_id'])) {
        $project['location'] = array(
          'city' => NULL,
          'country' => NULL,
          'postal_code' => NULL,
          'state_provice' => NULL,
          'street_address' => NULL,
        );
      } else {
        $countryId = $api['api.LocBlock.getsingle']['api.Address.getsingle']['country_id'];
        $country = $countryId ? CRM_Core_PseudoConstant::country($countryId) : NULL;

        $stateProvinceId = $api['api.LocBlock.getsingle']['api.Address.getsingle']['state_province_id'];
        $stateProvince = $stateProvinceId ? CRM_Core_PseudoConstant::stateProvince($stateProvinceId) : NULL;

        $project['location'] = array(
          'city' => $api['api.LocBlock.getsingle']['api.Address.getsingle']['city'],
          'country' => $country,
          'postal_code' => $api['api.LocBlock.getsingle']['api.Address.getsingle']['postal_code'],
          'state_province' => $stateProvince,
          'street_address' => $api['api.LocBlock.getsingle']['api.Address.getsingle']['street_address'],
        );
      }

      foreach ($api['api.VolunteerProjectContact.get']['values'] as $projectContact) {
        if (!array_key_exists('beneficiaries', $project)) {
          $project['beneficiaries'] = array();
        }

        $project['beneficiaries'][] = array(
          'id' => $projectContact['contact_id'],
          'display_name' => $projectContact['api.Contact.get']['values'][0]['display_name'],
        );
      }
    }

    foreach ($this->searchResults as &$need) {
      $projectId = (int) $need['project_id'];
      $need['project'] = $this->projects[$projectId];
    }
  }

  /**
   * Callback for usort.
   */
  private static function usortDateAscending($a, $b) {
    $startTimeA = strtotime($a['start_time']);
    $startTimeB = strtotime($b['start_time']);

    if ($startTimeA === $startTimeB) {
      return 0;
    }
    return ($startTimeA < $startTimeB) ? -1 : 1;
  }

  /**
   * Recommend Needs for Contact
   */
  public static function recommendedNeeds($cid, $dates = NULL) {

//   * filter Orgs by Interests/Impacts
    $schemaImpact = self::getCustomFieldSchema('Primary_Impact_Area');
    $schemaInterests = self::getCustomFieldSchema('Interests');

    $interests = civicrm_api3('Contact', 'getValue', array(
      'return' => 'custom_'.$schemaInterests['id'],
      'contact_id' => $cid,
    ));

    $lkpAvailability = self::getCustomFieldSchema('availability');

    $result = civicrm_api3('Contact', 'getValue', array(
      'sequential' => 0,
      'return' => $lkpAvailability['api_column_name'],
      'contact_id' => $cid
    ));

    foreach($result as $avail) {
      $availability[$avail] = $lkpAvailability['option_group']['options'][$avail];
    }

    $availabilitySql = self::getNeedsByAvailabilitySQL($availability);

    $interestsSQL = self::getProjectsByImpactAreaSQL($interests);

    $needSQL['SELECTS'] = array_merge_recursive($availabilitySql['SELECTS'], $interestsSQL['SELECTS']);

    $needSQL['JOINS'] = array_merge(
      $availabilitySql['JOINS'],
      $interestsSQL['JOINS'],
      array(array(
          'left' => 'civicrm_volunteer_project',
          'right' => 'civicrm_volunteer_project_contact',
          'on' => '`civicrm_volunteer_project`.`id` = `civicrm_volunteer_project_contact`.`project_id`'))
      );

    $needSQL['WHERES'] = self::composeWhereClauses($availabilitySql['WHERES'], $interestsSQL['WHERES'], 'AND');

    return self::fetchSelectQuery($needSQL);
  }

  /**
   * Flexible Need Key

//-- OPEN-ENDED
//duration IS NULL

//-- FLEXIBLE Time-Frame
//duration IS NOT NULL AND end_time IS NOT NULL

//  SET SHIFT
// duration IS NOT NULL AND end_time IS NULL
   */

/**
 * SET SHIFT
 * duration IS NOT NULL AND end_time IS NULL
 */
  static function whereNeedIsSetShift() {
    return array('WHERES' => array(
      array( 'field' => '`civicrm_volunteer_need`.`duration`', 'comp' => 'IS NOT NULL'),
      array( 'field' => '`civicrm_volunteer_need`.`end_time`', 'comp' => 'IS NULL')
    ));
  }

/**
 * DAYOFWEEK( n.start_time) NOT IN (1,7) -- weekdays
 */
  static function whereNeedIsWeekday() {
    return array('WHERES' => array(
      'DAYOFWEEK( `civicrm_volunteer_need`.`start_time` ) NOT in (1,7)'
    ));
  }
/**
 * DAYOFWEEK( n.start_time ) in (1,7) -- weekends
 */
  static function whereNeedIsWeekend() {
    return array(
      'DAYOFWEEK( `civicrm_volunteer_need`.`start_time` ) in (1,7)'
    );
  }
/**
 * HOUR (n.start_time) > 4 --evening
 */
  static function whereNeedIsEvening() {
    return array(
      'HOUR( `civicrm_volunteer_need`.`start_time` ) > 4'
    );
  }
  static function whereNeedIsNotPassed() {
    return array(
        array('field' => '`civicrm_volunteer_need`.`is_active`','value' => 1, 'type' => 'Integer'),
        array('paren' => 'AND',
          array('field' => '`civicrm_volunteer_need`.`end_time`', 'comp' => '> NOW()'),
          array('conj' => 'OR', '`civicrm_volunteer_need`.`end_time` IS NULL')
        ),
    );
  }

  static function getNeedsByAvailabilitySQL($availability) {

    $select = array();
    $select['civicrm_volunteer_need'] = array(
      'start_time', 'end_time', 'duration', 'is_flexible'
    );
    $select['civicrm_volunteer_project'] = array(
      'title', 'id as `project_id`'
    );

    $joins[] = array(
      'left' => 'civicrm_volunteer_need',
      'join' => 'INNER JOIN',
      'right' => 'civicrm_volunteer_project',
      'on' => 'civicrm_volunteer_need.project_id = civicrm_volunteer_project.id'
    );

    $where = array();
    $where = self::whereNeedIsNotPassed();
    if (in_array('Weekdays', $availability)) {
      $where = self::composeWhereClauses($where, self::whereNeedIsWeekday(), 'OR');
    }
    if (in_array('Weekday_Evenings', $availability)) {
      $weekdayEvenings = self::composeWhereClauses(self::whereNeedIsWeekday(), self::whereNeedIsEvening());
      $where = self::composeWhereClauses($where, $weekdayEvenings, 'OR');
    }

    if (in_array('Weekends', $availability)) {
      $weekends = self::whereNeedIsWeekend();
      $where = self::composeWhereClauses($where, $weekends, 'OR');
    }

    return array(
      'SELECTS' => $select,
      'JOINS' => $joins,
      'WHERES' => $where,
    );
  }

  static function fieldsToRecommendOn() {
    return array(
      'Interests',
      'Primary_Impact_Area',
      'Background_Check_Opt_In',
      'Spoken_Languages',
      'Agreed_to_Waiver',
      'Group_Volunteer_Interest',
      'Availability',
      'Board_Service_Opt_In',
      'How_Often',
      'Volunteer_Emergency_Support_Team_Opt_In',
      'Other_Skills',
      'Local_Arlington_Civic_Association_Opt_In',
      'Spoken_Languages_Other_',
    );
  }

  public static function matchingCustomFieldsMap() {
    return array(
      'Interests' => 'Primary_Impact_Area',
      'Background_Check_Opt_In' => 'Background_Check_Opt_In',
      'Spoken_Languages' => 'Spoken_Languages',
      'Agreed_to_Waiver' => 'Agreed_to_Waiver',
      'Group_Volunteer_Interest' => 'Group_Volunteer_Interest',
      'Availability' => 'Availability',
      'Board_Service_Opt_In' => 'Board_Service_Opt_In',
      'How_Often' => 'How_Often',
      'Volunteer_Emergency_Support_Team_Opt_In' => 'Volunteer_Emergency_Support_Team_Opt_In',
      'Other_Skills' => 'Other_Skills',
      'Local_Arlington_Civic_Association_Opt_In' => 'Local_Arlington_Civic_Association_Opt_In',
      'Spoken_Languages_Other_' => 'Spoken_Languages_Other_',
    );
  }

  static function getProjectsByImpactAreaSQL($areas) {
    $impactSchema = self::getCustomFieldSchema('Primary_Impact_Area');
    $tblOrgInformation = $impactSchema['custom_group']['table_name'];
    $fldImpactArea = $impactSchema['column_name'];
    $beneficiaryRelationshipType = civicrm_api3('OptionValue', 'getValue',
      array('name' => 'volunteer_beneficiary', 'return' => 'value')
      );

    $select = array('orgs' => array('id'), 'civicrm_volunteer_project_contact' => array('project_id'));

    $joins = array();
    $joins[] = array('join' => 'INNER JOIN',
      'left' => 'civicrm_contact orgs', 'right' => $tblOrgInformation ,
      'on' => "orgs.id = {$tblOrgInformation}.entity_id"
    );

    $joins[] = array(
      'left' => 'civicrm_contact orgs',
      'join' => 'INNER JOIN',
      'right' => 'civicrm_volunteer_project_contact',
      'on' => 'orgs.id = civicrm_volunteer_project_contact.contact_id'
      . ' AND civicrm_volunteer_project_contact.relationship_type_id = '. $beneficiaryRelationshipType
    );

    $where = array();
    foreach ($areas as $area) {
      $where[] = array('conj' => 'OR',
        'field' => "{$tblOrgInformation}.{$fldImpactArea}", 'value' => $area);
    }

    return array(
        'SELECTS' => $select,
        'JOINS' => $joins,
        'WHERES' => $where,
      );
  }

  /**
   * Invokes createSqlSelectStatement()
   * and CRM_Core_DAO::executeQuery().
   *
   * Builds an array based on specified fields.
   *
   * @param array $sqlParts array(
        'SELECTS' => $select,
        'JOINS' => $joins,
        'WHERES' => $where,
      )
   * @param array $returnFields - fields to return;
   *   Optional alias syntax: array('field' => 'alias')
   * @return array of specified fields, or all
   */
  static function fetchSelectQuery($sqlParts, $returnFields=NULL) {
    $query = self::createSqlSelectStatement($sqlParts);
    $dao = CRM_Core_DAO::executeQuery($query['sql'], $query['params']);

    while ($dao->fetch()) {
      $row = array();
      if (isset($returnFields)) {
        foreach ($returnFields as $field => $alias) {
          if (is_numeric($field)) {
            $field = $alias;
          }
          $row[$alias] = $dao->$field;
        }
      } else {
        $row = $dao->toArray();
      }
      $result[] = $row;
    }

    return $result;
  }
  /**
   * Prepare query and params for CRM_Core_DAO::executeQuery().
   *
   * At minnimum, components must have SELECTS and (TABLES or JOINS).
   * You may also provide components for WHERES, ORDER_BYS, and GROUP_BYS.
   *
   * More details in parseWHEREs().
   *
   * WARNING: may contain bugs if not specifying both sides of join.
   *
   * <pre>createSqlStatement( array(
   * 'SELECTS' => array('civicrm_contact' => array('id', 'display_name')),
   * 'JOINS' => array(
   *    array(
   *      'left' => 'civicrm_contact'
   *      'right' => 'civicrm_value_organization_information_5',
   *      'join' => 'INNER JOIN',
   *      'on' => 'civicrm_contact.id = civicrm_value_organization_information_5.entity_id')
   *    )
   * ),
   * 'WHERES' => array(
   *    array('conj' => 'AND', 'field' => 'civicrm_contact.first', 'value' => $first, 'type' => 'String'),
   *    array('conj' => 'AND', 'field' => 'civicrm_contact.is_active', 'value' => TRUE, 'type' => 'Boolean')
   *   )
   * );</pre>
   *
   * @param array $components
   * @return array( 'sql' => ..., 'params' => ... ) for invocation of CRM_Core_DAO::executeQuery()
   */
  static function createSqlSelectStatement($components=array()){
    if (array_key_exists('SELECTS', $components) &&
       (array_key_exists('TABLES', $components) || array_key_exists('JOINS', $components))
      ) { // good
    }else {
      // please come again
      throw new CRM_Exception('minnimum components missing: '.__FILE__.':'.__LINE__);
    }
    $SELECTS = CRM_Utils_Array::value('SELECTS', $components);
    $TABLES = CRM_Utils_Array::value('TABLES', $components, array());
    $JOINS = CRM_Utils_Array::value('JOINS', $components, array());
    $WHERES = CRM_Utils_Array::value('WHERES', $components, array());
    $GROUP_BYS = CRM_Utils_Array::value('GROUP_BYS', $components, array());
    $ORDER_BYS = CRM_Utils_Array::value('ORDER_BYS', $components, array());

    if (is_array($SELECTS)) {
      foreach ($SELECTS as $table => $columns) {
        $tmp = array();
        foreach ($columns as $col) {
          $tmp[] = "{$table}.{$col}";
        }
        if (isset($clzSelect)) {
          $clzSelect .= ', ';
        }
        $clzSelect .= join(', ', $tmp);
      }
    } else {
      $clzSelect = $SELECTS;
    }

    if (isset($JOINS)) {
      $tables = array();
      $unary = array();
      $binary = array();
      $tableCount = array();
      $unaryTableCount = array();
      foreach ($JOINS as &$join) {
        $left = $right = NULL;
        if (isset($join['left']) && isset($join['right'])) {
          $left = $join['left'];
          $right = $join['right'];
          if (array_key_exists($left, $tableCount)) {
            $tableCount[$left]++;
          } else {
            $tableCount[$left] = 1;
          }
          if (array_key_exists($right, $tableCount)) {
            $tableCount[$right]++;
          } else {
            $tableCount[$right] = 1;
          }
          $join['binary'] = 1;
          $binary[] = $join;
        } else { // unary
          $left = $right = NULL;
          $left = (isset($join['left'])) ? $join['left'] : FALSE;
          $right = (isset($join['right'])) ? $join['right'] : FALSE;
          // normalize to use 'right'
          $join['right'] = ($left) ? $left : $right;
          if (array_key_exists($join['right'], $tableCount)) {
            $tableCount[$join['right']]++;
          } else {
            $tableCount[$join['right']] = 1;
          }
          if (array_key_exists(($join['right']), $unaryTableCount)) {
            $unaryTableCount[$join['right']]++;
          } else {
            $unaryTableCount[$join['right']] = 1;
          }
          $join['unary'] = 1;
          $unary[] = $join;
        }
      }
      reset($JOINS);
      $primary = FALSE;
      $maxTries = count($JOINS);
//      $log = '';
      $done = FALSE;
      while (count($JOINS) > 0) {
        $process = FALSE;
        $join = array_pop($JOINS);

        /**
         * TODO: try new strategy - it is highly problematic supporting joins that
         * don't specify left and right. Try ...
         *  * Look for join with right-match and un-matched left - make first
         *  * Build chain of matching joins
         *  * for each right-join, search for multiple left-matches
         *  * if remaining join, throw exception
         *
         * ... or don't try to auto-order joins at all. Idea was to support
         * auto-composing querries.
         *
         */
//        $log .= 'SHIFT'.var_export($join, TRUE)."\n ~~~~".count($JOINS);
        // decide to process or postpone
        if (
          ( !$primary && $join['unary'] === 1)
          || ( // binary:
            !$primary
              && ($tableCount[$join['left']] > 1
              && $tableCount[$join['right']] > 1)
            || ( $primary && ($tableCount[$join['left']] > 1
                || $tableCount[$join['right']] > 1 )
              )
          || (
            $primary
            && $join['binary'] === 1
            && ($tableCount[$join['left']] < 2
              || $tableCount[$join['right']] < 2)
            )
          )
        ) {//postpone
          if ($join['pushed'] > $maxTries) {
            // failsafe
            $process = TRUE;
          } else {
            if (isset($join['pushed'])) {
              $join['pushed']++;
            } else {
              $join['pushed'] = 1;
            }
            $log .= 'POSTPONE:'.var_export($join, TRUE);
            array_unshift($JOINS, $join);
            next($JOINS);
          }
        } else {
          $process = TRUE;
          $primary = ($join['binary'] === 1) ? TRUE: $primary;
$log .= 'PROCESS:'.var_export($join, TRUE);
        }

        if ($process) {
          if (!is_array($join)) {
            throw new CRM_Exception('Check your array format'.__FILE__.__LINE__);
          }
          if (!isset($join['join'])) {
            $join['join'] = 'INNER JOIN';
          }

          $left = $right = $both = NULL;

          if ($join['binary'] && !isset($clzFrom)) {
            $both = true;
          } else if($join['binary']) {
            if (in_array($join['left'], $tables)) {
              $right = $join['right'];
            }
            if (in_array($join['right'], $tables)) {
              $left = $join['left'];
            }
            $both = ($left && $right)? TRUE : FALSE ;
          }

          if ($both) {
            $clzFrom .= " {$join['left']} {$join['join']} {$join['right']} on {$join['on']}";
            $tables[] = $join['left'];
            $tables[] = $join['right'];
          } else {
            if ($join['binary'] && in_array($join['right'], $tables)) {
              $table = $join['left'];
            } else {
              $table = $join['right'];
            }
            $clzFrom .= " {$join['join']} {$table} on {$join['on']}";
            $tables[] = $table;
          }
        }
      } // while
    }

    if (!isset($clzFrom)) {
      $clzFrom = implode(', ', array_merge($TABLES, $tables));
    }

    $parseWHERE = self::parseWHEREs($WHERES);
    $clzWhere = $parseWHERE['WHERE'];
    $params = $parseWHERE['params'];

    if (count($GROUP_BYS)) {
      $clzGroupBy = join(', ', $GROUP_BYS);
    }
    if (count($ORDER_BYS)) {
      $clzOrderBy = join(', ', $ORDER_BYS);
    }

    return array( 'sql' =>
      "SELECT {$clzSelect} FROM {$clzFrom}"
      . ((isset($clzWhere))? " WHERE {$clzWhere}" : '')
      . ((isset($clzGroupBy))? " GROUP BY {$clzGroupBy}" : '')
      . ((isset($clzOrderBy))? " ORDER BY {$clzOrderBy}" : ''),
      'params' => $params
    );
  }

  /**
   * Creates SQL WHERE clause and params for CRM_Core_DAO::executeQuery();
   *
   * @param type $WHERES
   * Each test is an array and can have keys for:
   * 'field', 'value', 'type', 'conj'(unction), and 'comp'(arison).
   * Type defaults to 'String'. Conjunction defaults to 'AND'. Comparison defaults to '='.
   *
   * For parameter escaping and validation, supply 'value'.
   * Types should conform to CRM_Core_Util_Type::validate()
   *
   * Alternate syntaxes (passthrough): <pre>$WHERES = array(
   * array('field' => 'civicrm_volunteer_need.end_time', 'comp' => '> NOW()'),
   * array('conj' => 'OR', 'civicrm_volunteer_need.end_time IS NOT NULL'),
   * '`year` = 2016'
   * );</pre>
   * For parenthesis, wrap the clausees in an array and add a magic key to the
   * wrapper that specifies the conjunction, e.g.: 'parens' => 'AND'
   * Supported keys: parenthetical; paren; parenthesis; sub;
   *
   * @param int $n (optional) starting index for params array (needed for recursion)
   *
   * @return array('WHERE' => '...', 'params' => array())
   * $params = array( 1 => array( 'value', 'type')) // see CRM_Utils_Type::validate() re type
   * @throws CRM_Exception
   */
  static function parseWHEREs($WHERES, &$n=0) {
    $params = array();
    foreach ($WHERES as $key => $where) {
      $conj = $paren = $recurse = NULL;
      if (is_array($where)) {
        $recurse = self::normalizeParenthetical($where);
      } else {
        $passthrough = TRUE;
      }

      if ($recurse) {
        //explicit syntax for sub-clause
        $conj = $where[$recurse];
        unset($where[$recurse]);

        if (strtoupper($key) == 'AND' || strtoupper($key) == 'OR') {
        // lazy syntax for sub-clause
          $conj = $key;
        }

        $passthrough = TRUE;
        $conj = strtoupper($conj);

        $parsed = self::parseWHERES($where, $n);
        $params = array_merge($params, $parsed['params']);

        $where = "({$parsed['WHERE']})";
      }

      if (!$passthrough) {
        if (array_key_exists('conj', $where)) {
          $conj = strtoupper(trim($where['conj']));
        }

        if (array_key_exists('field', $where)) {
          if (!array_key_exists('comp', $where)) {
            $where['comp'] = '=';
            if (!array_key_exists('value', $where)) {
              throw new CRM_Exception("'value' array required: ".__FILE__.':'.__LINE__);
            }
          }

          if (array_key_exists('value', $where) ) {
            if(!array_key_exists('type', $where)) {
              $where['type'] = 'String';
            }

            $params[$n] = array($where['value'], $where['type']);
            $where = "{$where['field']} {$where['comp']} %{$n}";
            $n++;
          } else {
            // e.g. comp is  'IS NULL' or  '> NOW()'
            $where = "{$where['field']} {$where['comp']}";
          }
        } elseif (count($where) === 2) {
          // array('conj' => or, '<arbitrary where clause string>')
          unset($where['conj']);
          $where = array_pop($where);
        } elseif (count($where) === 1)  {
          // un-wrap overzealous array nesting
          $where = array_pop($where);
        }
      }

      if (!isset($conj)) {
        $conj = 'AND';
      }

      if (is_array($where)) {
        throw new CRM_Exception("oops - Array; check your array format:".__FILE__.':'.__LINE__.
          var_export($where, TRUE));
      }
      if (trim($where) == '') {
        throw new CRM_Exception("zero-length clause encountered; check your array format: ".__FILE__.':'.__LINE__);
      }

      $clzWhere .= (isset($clzWhere))? " $conj $where" : $where;
    }

    return (isset($clzWhere))? array('WHERE' => $clzWhere, 'params' => $params): NULL;
  }

  /**
   *
   * @param array $where (by reference)
   * @return String or False if non-parenthetical
   */
  static function normalizeParenthetical(&$where) {
    $first = NULL;
    if (!is_array($where)) {
      throw new CRM_Exception('not array:'.__FILE__.__LINE__);
    }
    foreach($where as $key => $v) {
      if (is_numeric($key)) {
        // Necessary:
        // switch 'loose comparison' SUCKS!
        continue;
      }
      switch ($key) {
        case 'paren':
        case 'sub':
        case 'parenthesis':
        case 'parenthetical':
          $first = $key;
          break 2;
      }
    }

    if (isset($first)) {
      $parens = array('parenthetical','parenthesis', 'paren', 'sub');
      foreach ($parens as $paren) {
        if ($paren == $first) {
          continue;
        }
        if (array_key_exists($paren, $where)) {
          $tmp = $where[$paren];
          unset($where[$paren]);
          $where[$first] = $tmp;
        }
      }
      //clean-up array_merge_recursive() resultant arrays:
      if (is_array($where[$first])) {
        $where[$first] = array_pop($where[$first]);
      }
      return $first;
    }
    return FALSE;
  }

  /**
   * Given an array of field names,
   * use metadata to pair fields that share the same option group.
   *
   * @param array $fields
   * @return array map
   */
  static function autoMatchFieldByOptionGroup($fields=array()) {
    foreach ($fields as $field) {
      $schema = self::getCustomFieldSchema($field);
      if (array_key_exists('option_group_id', $schema)) {
        $meta[$field] = $schema['option_group']['name'];
      }
    }

    $matches = array();
    foreach ($meta as $field => $opt_grp) {
      $match = array_intersect($meta,array($opt_grp));
      if (count($match) > 1) {
        $a = array_keys($match);
        $b = array_reverse($a);
        $fields = array_combine($a, $b);
      }

      end($fields);
      do  {
        $field_b = current($fields);
        $field_a = key($fields);
        if (array_key_exists($field_b, $fields) === true) {
          // de-dupe:
          unset($fields[$field_a]);
        } else {
          $matches[$field_a] = $field_b;
        }
        ;
      } while (prev($fields) !== false);
    }

    return $fields;
  }

/**
 * api.CustomField.get with chained CustomGroup and OptionGroup/Value.
 * returns a subset of the API result fields, relevant to schemas.
 * Chained calls are accessible via
 * <ul><li>custom_group</li><li>option_group</li><li>option_group > Options</li>
 *
 * @param type $field
 * @return array()
 */
  static function getCustomFieldSchema($field) {
    $result = civicrm_api3('CustomField', 'get',
      array(
        'sequential' => 0,
        'name' => $field,
        'is_active' => '1',
        'return' => array(
          'column_name',
          'custom_group_id',
          'id',
          'name',
          'label',
          'data_type',
          'html_type',
          'is_required',
          'option_group_id',
        ),
        'api.CustomGroup.get' => array('id' => '$value.custom_group_id'),
        'api.OptionGroup.get' => array('id' => '$value.option_group_id'),
        'api.OptionValue.get' => array(
          'option_group_id' => '$value.option_group_id',
          'is_active' => 1,
          'sequential' => 0,
          'return' => array('name','value')
        )
      )
    );

    $schema = self::extractFields($result,
      array(
        'column_name',
        'custom_group_id',
        'id',
        'name',
        'label',
        'data_type',
        'html_type',
        'is_required',
        'option_group_id',
      )
    );
    $schema['api_column_name'] = "custom_{$schema['id']}";

    $schema['custom_group'] = self::extractChainedApi('api.CustomGroup.get', $result,
      array(
        'id',
        'name',
       'table_name',
        'extends',
        'weight',
        'is_multiple',
        'is_active'
      )
    );

    if (array_key_exists('option_group_id', $schema)) {
      $schema['option_group'] = self::extractChainedApi('api.OptionGroup.get', $result);
      $result = self::extractChainedApi('api.OptionValue.get', $result);
      foreach($result as $option) {
        $options[$option['value']] = $option['name'];
      }
      $schema['option_group']['options'] = $options;
    }

    return $schema;
 }

 /**
  * fetch the values array from an api chain call
  *
  * @param string $chainKey identifies the API-Chain call
  * @param api $result civicrm api result
  * @param array $keys (optional) fields to return, all if empty
  * @return array api $result['values']
  */
  static function extractChainedApi($chainKey, $result, $keys=array()) {
    $chained = array_pop(self::extractFields($result, array($chainKey)));
    return self::extractFields($chained, $keys);
  }

  /**
   * returns the fields specified, from an api $result.
   * Can only return fields that are siblings.
   * Uses 'values' array if it is present.
   *
   * @param api civicrm api result
   * @param array $keys (optional) fields to return, all if empty
   * @return array $result['values']
   */
  static function extractFields($result, $keys=array()) {
    if (!is_array($result)) {
      return array();
    }
    $_keys = array_flip($keys);
    $items = (array_key_exists('values', $result)) ? $result['values'] : $result;
    $return = array();
    foreach ( $items as $key => $item ) {
      $return[$key] = ( count($_keys)>0 )
        ? array_intersect_key($item, $_keys)
        : $item ;
    }
    return (count($return) == 1 && is_array(current($return)))
      ? array_pop($return) : $return;
  }

  /**
   * YAGNI
   * un-tested
   * @param type $WHERES
   * @param type $params
   * @param type $n
   */
  static function reNumberParams(&$WHERES, &$params, $n) {
    foreach ($WHERES as &$where) {
      $match = array();
      preg_match('#%\n', $where, $match);
      $oldIndex = $match[0][1];
      str_replace($match[0], '%'.$n, $where);
      $newParams[$n++] = $params[$oldIndex];
    }
    $params = $newParams;
  }

  /**
   * Merge WHERE arrays.
   *
   * @param Array $where see format in parseWHEREs()
   * @param Array $add
   * @param String $paren - add as parenthetical (specify AND/OR)
   */
  static function composeWhereClauses($where, $add, $paren=NULL) {
    if (!is_array($where)) {
      throw new CRM_Exception('Missing $where parameter to composeWhereClauses()');
    }
    if (isset($paren)) {
      if (array_key_exists('WHERES', $add)) {
        $add['WHERES']['paren'] = $paren;
      } else {
        $add['paren'] = $paren;
      }
    }
    if (array_key_exists('WHERES', $where)
      && !array_key_exists('WHERES', $add)) {
      $add = array('WHERES' => $add);
    }
    if (!array_key_exists('WHERES', $where)
      && array_key_exists('WHERES', $add)) {
      $add = $add['WHERES'];
    }

    if (array_key_exists('WHERES', $where)) {
      if ($paren) {
        $where['WHERES'][] = $add['WHERES'];
      } else {
        $where['WHERES'] = array_merge_recursive($where['WHERES'], $add['WHERES']);
      }
    } else {
      if ($paren) {
        $where[] = $add;
      } else {
        $where = array_merge_recursive($where, $add);
      }
    }
    return $where;
  }
}
