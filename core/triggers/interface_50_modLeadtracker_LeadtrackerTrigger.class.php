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
 *  \file       core/triggers/interface_50_modLeadtracker_LeadtrackerTrigger.class.php
 *  \ingroup    leadtracker
 *  \brief      Trigger handler for Lead Tracker automation.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 *  LeadtrackerTrigger
 *
 *  Fires on native events (proposal validated/signed, order validated, invoice
 *  validated, action created) and delegates stage advancement to
 *  LeadtrackerAutomation.
 */
class LeadtrackerTrigger extends DolibarrTriggers
{
	public $name        = 'LeadtrackerTrigger';
	public $description = 'Lead tracker automation trigger';
	public $version     = '1.0.0';
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

		$watched = array(
			'PROPAL_VALIDATE',
			'PROPAL_SIGN',
			'ORDER_VALIDATE',
			'COMMANDE_VALIDATE',
			'BILL_VALIDATE',
			'ACTION_CREATE',
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

		foreach ($projectIds as $pid) {
			$automation->maybeAdvance((int) $pid);
		}

		return 0;
	}

	/**
	 *  Collect project IDs linked to the triggering object.
	 *
	 *  Checks direct FK columns (fk_projet for documents, fk_project for
	 *  actioncomm) and also llx_element_element for links added after creation.
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
