<?php
/* Copyright (C) 2026 Lead Tracker contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       core/triggers/interface_99_modLeadtracker_LeadtrackerTrigger.class.php
 *  \ingroup    leadtracker
 *  \brief      Trigger handler for Lead Tracker automation.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 *  InterfaceLeadtrackerTrigger
 *
 *  Fires on native events (proposal validated/signed, order validated, invoice
 *  validated, action created) and delegates stage advancement to
 *  LeadtrackerAutomation.
 */
class InterfaceLeadtrackerTrigger extends DolibarrTriggers
{
	public $name        = 'InterfaceLeadtrackerTrigger';
	public $description = 'Lead tracker automation trigger';
	public $version     = '1.4.1';
	public $picto       = 'projectpub';

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		parent::__construct($db);
	}

	/**
	 *  Run the trigger.
	 *
	 *  @param  string     $action
	 *  @param  object     $object
	 *  @param  User       $user
	 *  @param  Translate  $langs
	 *  @param  Conf       $conf
	 *  @return int         0 = OK, <0 = error
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('leadtracker')) {
			return 0;
		}

		// Project-save events: recalculate values only (no stage advancement).
		if ($action === 'PROJECT_MODIFY' || $action === 'PROJECT_CREATE') {
			if (empty($object->id)) {
				return 0;
			}
			require_once dol_buildpath('/leadtracker/class/leadtrackerautomation.class.php', 0);
			$automation = new LeadtrackerAutomation($this->db);
			$automation->recalculateValues((int) $object->id);
			return 0;
		}

		// Lifecycle goes live (Draft->Validated, or Closed->Reopened): the funnel was
		// frozen, so re-evaluate stages now in case evidence accumulated while frozen
		// (e.g. an email was sent before the project was validated).
		if ($action === 'PROJECT_VALIDATE' || $action === 'PROJECT_REOPEN') {
			if (empty($object->id)) {
				return 0;
			}
			require_once dol_buildpath('/leadtracker/class/leadtrackerautomation.class.php', 0);
			$automation = new LeadtrackerAutomation($this->db);
			$automation->maybeAdvance((int) $object->id);
			return 0;
		}

		// Project card "Send email" — the object IS the project, contact is a known fact.
		// Dolibarr fires PROJECT_SENTBYMAIL here; ACTION_CREATE only fires if the global
		// "create event on send" agenda option is enabled, making it unreliable on its own.
		if ($action === 'PROJECT_SENTBYMAIL') {
			if (empty($object->id)) {
				return 0;
			}
			require_once dol_buildpath('/leadtracker/class/leadtrackerautomation.class.php', 0);
			$automation = new LeadtrackerAutomation($this->db);
			$automation->maybeAdvance((int) $object->id, array('has_outbound_contact' => true));
			return 0;
		}

		$watched = array(
			// Stage-advancing document events
			'PROPAL_VALIDATE',
			'PROPAL_SIGN',
			'ORDER_VALIDATE',
			'COMMANDE_VALIDATE',
			'BILL_VALIDATE',
			// Contact events — logged actions and sending docs by email
			'ACTION_CREATE',
			'ACTION_MODIFY',
			'PROPAL_SENTBYMAIL',
			'ORDER_SENTBYMAIL',
			'BILL_SENTBYMAIL',
		);

		if (!in_array($action, $watched)) {
			return 0;
		}

		$projectIds = $this->findLinkedProjects($object);
		if (empty($projectIds)) {
			return 0;
		}

		require_once dol_buildpath('/leadtracker/class/leadtrackerautomation.class.php', 0);
		$automation = new LeadtrackerAutomation($this->db);

		// For send-by-mail events, outbound contact is a known fact — pass it
		// directly so the automation does not need to rely on an actioncomm entry
		// being present (which only happens when Dolibarr's "create event on send"
		// global option is enabled).
		$forcedEvidence = array();
		if (in_array($action, array('PROPAL_SENTBYMAIL', 'ORDER_SENTBYMAIL', 'BILL_SENTBYMAIL'))) {
			$forcedEvidence['has_outbound_contact'] = true;
		}

		foreach ($projectIds as $pid) {
			$automation->maybeAdvance((int) $pid, $forcedEvidence);
		}

		return 0;
	}

	/**
	 *  Collect project IDs linked to the triggering object.
	 *
	 *  Checks direct FK columns (fk_projet for documents, fk_project for
	 *  actioncomm) and also element_element for links added after creation.
	 *
	 *  @param  object  $object  The triggering native object
	 *  @return int[]             Unique project IDs
	 */
	private function findLinkedProjects($object)
	{
		$ids = array();

		// Proposals, orders, invoices carry fk_projet
		if (!empty($object->fk_projet)) {
			$ids[] = (int) $object->fk_projet;
		}

		// actioncomm uses fk_project (not fk_projet)
		if (!empty($object->fk_project)) {
			$ids[] = (int) $object->fk_project;
		}

		// Some Dolibarr versions link actioncomm to a project via the generic
		// fk_element / elementtype pair instead of (or in addition to) fk_project.
		if (!empty($object->fk_element) && !empty($object->elementtype)
			&& $object->elementtype === 'project') {
			$ids[] = (int) $object->fk_element;
		}

		// Also look in element_element for links added after object creation
		if (!empty($object->id) && !empty($object->element)) {
			$esc = $this->db->escape($object->element);
			$oid = (int) $object->id;
			$sql = "SELECT fk_source as pid FROM ".MAIN_DB_PREFIX."element_element"
				." WHERE fk_target = ".$oid
				." AND targettype = '".$esc."'"
				." AND sourcetype = 'project'"
				." UNION "
				."SELECT fk_target as pid FROM ".MAIN_DB_PREFIX."element_element"
				." WHERE fk_source = ".$oid
				." AND sourcetype = '".$esc."'"
				." AND targettype = 'project'";
			$res = $this->db->query($sql);
			if ($res) {
				while ($row = $this->db->fetch_object($res)) {
					$ids[] = (int) $row->pid;
				}
			}
		}

		return array_unique(array_filter($ids));
	}
}
