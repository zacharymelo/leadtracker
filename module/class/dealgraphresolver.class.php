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
 *  \file       class/dealgraphresolver.class.php
 *  \ingroup    leadtracker
 *  \brief      Builds the branching deal graph (lead -> proposals -> orders ->
 *              invoices/shipments) from native data. Read-only.
 */

/**
 *  DealGraphResolver
 *
 *  A "deal" is the project/opportunity (the lead) plus every commercial-spine
 *  document hung off it. Unlike the linear LeadProgressResolver step bar, this
 *  models the deal as a directed graph rooted at the lead: one lead can fan out
 *  into many proposals, a winning proposal into orders, an order into several
 *  invoices and shipments. Lost/refused/cancelled branches are kept (the point
 *  of a branch view) but flagged dead so the renderer can de-emphasize them.
 *
 *  Topology is built entirely from native data here — the module works fully on
 *  its own. When the Order Progress Tracker module is also enabled, $opEnabled
 *  is set so the renderer can offer per-document drill-down into that module's
 *  deeper workflow view. No code dependency on it either way.
 *
 *  Node state vocabulary:
 *    pending  - exists but not yet advanced (draft)
 *    active   - validated / in progress
 *    done     - terminal success (signed, paid, closed, processed)
 *    dead     - lost / refused / cancelled (rendered, de-emphasized)
 */
class DealGraphResolver
{
	const STATE_PENDING = 'pending';
	const STATE_ACTIVE  = 'active';
	const STATE_DONE    = 'done';
	const STATE_DEAD    = 'dead';
	const STATE_EVENT   = 'event';

	/** @var DoliDB */
	public $db;

	/** @var bool  True when the orderprogress module is enabled (drill-down). */
	public $opEnabled = false;

	/** @var bool  Attach agenda events (calls/emails/meetings) as leaf cards. */
	public $includeEvents = true;

	/** @var bool  Attach warranty records (warrantysvc) under their order. */
	public $includeWarranty = true;

	/** @var bool  Attach phone-call records (pbxcalls) under the lead. */
	public $includeCalls = true;

	/** @var bool  Broad correspondence scope: include everything matched to the
	 *             deal's CUSTOMER (fk_soc) — calls, emails, SMS, meetings — not just
	 *             items tagged to this project. Customer-only items are flagged
	 *             'broad' for the renderer to mark/dim. */
	public $broad = false;

	/** @var array  Aux leaf nodes discovered during the link walk (key => node). */
	private $auxNodes = array();

	/** @var array  Aux edges discovered during the link walk (list of parent->aux). */
	private $auxEdges = array();

	/**
	 *  Non-spine linked-object types captured as leaf cards. Keyed by the
	 *  fetchObjectLinked element key. card = native view URL prefix.
	 *
	 *  @var array
	 */
	private static $auxTypes = array(
		'warrantysvc_svcwarranty' => array('kind' => 'warranty', 'label' => 'LeadtrackerNodeWarranty', 'card' => '/custom/warrantysvc/warranty_card.php?id='),
		'warrantysvc_svcrequest'  => array('kind' => 'request',  'label' => 'LeadtrackerNodeRequest',  'card' => '/custom/warrantysvc/card.php?id='),
	);

	/**
	 *  Commercial-spine type config. Keyed by the normalized type used as node
	 *  type and as the element_element type string. `table` is the SQL table base
	 *  (without prefix); `rank` orients edges (parent = lower rank); `card` is the
	 *  native view URL prefix; `amount` is the money column or null; `byProject`
	 *  is whether the table carries fk_projet for a membership query.
	 *
	 *  Shipments link from their order via element_element and do not reliably
	 *  carry fk_projet, so byProject is false — they are pulled in as linked nodes.
	 *
	 *  @var array
	 */
	private static $types = array(
		'propal'   => array('table' => 'propal',     'rank' => 1, 'card' => '/comm/propal/card.php?id=',   'amount' => 'total_ttc', 'byProject' => true),
		'commande' => array('table' => 'commande',   'rank' => 2, 'card' => '/commande/card.php?id=',       'amount' => 'total_ttc', 'byProject' => true),
		'facture'  => array('table' => 'facture',     'rank' => 3, 'card' => '/compta/facture/card.php?id=', 'amount' => 'total_ttc', 'byProject' => true),
		'shipping' => array('table' => 'expedition',  'rank' => 3, 'card' => '/expedition/card.php?id=',     'amount' => null,        'byProject' => false),
	);

	/**
	 *  Meaningful spine relationships, used both to filter element_element rows
	 *  down to the deal spine and to label edges. Keyed by "parentType>childType"
	 *  (canonical low-rank -> high-rank direction).
	 *
	 *  @var array
	 */
	private static $spinePairs = array(
		'propal>commande' => 'LeadtrackerEdgeOrdered',
		'commande>facture' => 'LeadtrackerEdgeInvoiced',
		'commande>shipping' => 'LeadtrackerEdgeShipped',
		'propal>facture'   => 'LeadtrackerEdgeInvoicedDirect',
	);

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->opEnabled = (function_exists('isModEnabled') && isModEnabled('orderprogress'));
	}

	/**
	 *  Admin diagnostic: dump exactly how a project's spine documents are linked,
	 *  from two independent angles — the raw element_element table, and Dolibarr's
	 *  own fetchObjectLinked() resolver. Tells us whether the docs are genuinely
	 *  unlinked (only co-members of the project) or linked by a mechanism the graph
	 *  builder isn't reading. Returns plain text for an admin-only <pre> dump.
	 *
	 *  @param  int  $projectId
	 *  @return string
	 */
	public function diagnose($projectId)
	{
		$pid   = (int) $projectId;
		$lines = array();
		$lines[] = "== Deal graph diagnostic for project ".$pid." ==";

		// 1. Spine docs by fk_projet (the membership angle), with their ids.
		$idsByType = array();
		$allIds    = array($pid);
		$lines[] = "\n-- Documents with fk_projet = ".$pid." --";
		foreach (self::$types as $type => $cfg) {
			if (empty($cfg['byProject'])) {
				$lines[] = $type.": (no fk_projet column — gathered via links only)";
				continue;
			}
			$sql = "SELECT rowid, ref, fk_statut FROM ".MAIN_DB_PREFIX.$cfg['table']
				." WHERE fk_projet = ".$pid." AND entity IN (".getEntity($cfg['table']).")";
			$res = $this->db->query($sql);
			$rowsTxt = array();
			if ($res) {
				while ($o = $this->db->fetch_object($res)) {
					$idsByType[$type][(int) $o->rowid] = $o->ref;
					$allIds[] = (int) $o->rowid;
					$rowsTxt[] = $o->ref."(id ".$o->rowid.", statut ".$o->fk_statut.")";
				}
			}
			$lines[] = $type.": ".(empty($rowsTxt) ? "(none)" : implode(', ', $rowsTxt));
		}

		// 2. EVERY element_element row touching those ids — no type filter, so we
		//    see the real source/target type strings and any bridging doc ids.
		$idList = implode(',', array_unique(array_map('intval', $allIds)));
		$lines[] = "\n-- element_element rows touching any of those ids --";
		$sql = "SELECT rowid, fk_source, sourcetype, fk_target, targettype FROM ".MAIN_DB_PREFIX."element_element"
			." WHERE fk_source IN (".$idList.") OR fk_target IN (".$idList.")";
		$res = $this->db->query($sql);
		$n = 0;
		if ($res) {
			while ($o = $this->db->fetch_object($res)) {
				$lines[] = "  ".$o->sourcetype."(".$o->fk_source.")  ->  ".$o->targettype."(".$o->fk_target.")";
				$n++;
			}
		}
		if ($n === 0) {
			$lines[] = "  (NONE — these documents are not linked in element_element at all)";
		}

		// 3. Dolibarr's own view via fetchObjectLinked(), per document.
		$lines[] = "\n-- fetchObjectLinked() per document (Dolibarr's canonical links) --";
		$loaders = array(
			'propal'   => array('/comm/propal/class/propal.class.php', 'Propal'),
			'commande' => array('/commande/class/commande.class.php', 'Commande'),
			'facture'  => array('/compta/facture/class/facture.class.php', 'Facture'),
		);
		foreach ($idsByType as $type => $idmap) {
			if (!isset($loaders[$type])) {
				continue;
			}
			list($path, $cls) = $loaders[$type];
			if (!class_exists($cls)) {
				@dol_include_once($path);
			}
			if (!class_exists($cls)) {
				$lines[] = $type.": (class ".$cls." unavailable)";
				continue;
			}
			foreach ($idmap as $id => $ref) {
				$obj = new $cls($this->db);
				if ($obj->fetch($id) <= 0) {
					$lines[] = "  ".$ref.": fetch failed";
					continue;
				}
				$obj->linkedObjects = array();
				@$obj->fetchObjectLinked();
				$parts = array();
				if (!empty($obj->linkedObjects) && is_array($obj->linkedObjects)) {
					foreach ($obj->linkedObjects as $lt => $list) {
						$cnt = is_array($list) ? count($list) : 0;
						$ids = array();
						if (is_array($list)) {
							foreach ($list as $lo) {
								if (is_object($lo) && !empty($lo->id)) {
									$ids[] = $lo->id;
								}
							}
						}
						$parts[] = $lt."[".implode(',', $ids)."]";
					}
				}
				$lines[] = "  ".$type." ".$ref."(".$id."): ".(empty($parts) ? "(no linked objects)" : implode('  ', $parts));
			}
		}

		// 4. The ACTUAL build path the rendered graph uses — so we can see exactly
		//    where it diverges from the fetchObjectLinked() truth above. If this
		//    section is missing entirely, an old resolver is still loaded.
		$lines[] = "\n-- BUILD PATH (linked-objects walk, v1.6.x) --";
		$seed = $this->gatherSeedIds($pid);
		foreach ($seed as $t => $sids) {
			$lines[] = "seed ".$t.": ".(empty($sids) ? "(none)" : implode(',', $sids));
		}
		list($collected, $pairs, $display) = $this->collectViaLinks($seed);
		foreach ($collected as $t => $m) {
			$has = array();
			foreach (array_keys($m) as $cid) {
				$has[] = $cid.(isset($display[$t.':'.$cid]) ? '' : '(NO DISPLAY)');
			}
			$lines[] = "collected ".$t.": ".(empty($has) ? "(none)" : implode(',', $has));
		}
		$lines[] = "pairs (".count($pairs)."):";
		foreach ($pairs as $p) {
			$lines[] = "   ".$p[0][0].":".$p[0][1]."  ~  ".$p[1][0].":".$p[1][1];
		}
		$graph = $this->build($pid);
		$lines[] = "nodes: ".implode('  ', array_keys($graph['nodes']));
		$lines[] = "edges (".count($graph['edges'])."):";
		foreach ($graph['edges'] as $e) {
			$lines[] = "   ".$e['from']."  ->  ".$e['to'];
		}

		return implode("\n", $lines);
	}

	/**
	 *  Build the deal graph for a project.
	 *
	 *  @param  int  $projectId  Project (lead) rowid
	 *  @return array            ['root' => key, 'nodes' => map, 'edges' => list]
	 *                           Empty nodes/edges when nothing is linked.
	 */
	public function build($projectId)
	{
		$projectId = (int) $projectId;
		$nodes = array();
		$edges = array();

		// Root = the lead itself.
		$rootKey = 'project:'.$projectId;
		$nodes[$rootKey] = $this->buildRootNode($projectId);

		// Step 1 — seed with the project's own documents, then walk Dolibarr's
		// canonical linked-object graph outward. fetchObjectLinked() is type-aware
		// (element_element ids collide wildly across tables — commande #85 and
		// facture #85 are different docs — so a raw id query cannot be trusted) and
		// it transparently pulls in bridging orders and shipments that carry no
		// fk_projet of their own.
		$seed = $this->gatherSeedIds($projectId);
		list($collected, $pairs, $display) = $this->collectViaLinks($seed);

		// Step 2 — build a node per collected document from the display fields read
		// off its loaded object during the walk.
		foreach ($collected as $type => $idmap) {
			foreach (array_keys($idmap) as $id) {
				$key = $type.':'.(int) $id;
				if (!isset($display[$key])) {
					continue; // object failed to load — skip rather than half-render
				}
				$nodes[$key] = $this->buildDocNode($type, $display[$key]);
			}
		}

		// Step 3 — directed spine edges from the linked-object pairs.
		$edges = $this->edgesFromPairs($pairs, $nodes);

		// Step 3.4 — warranty/request aux records captured during the walk, each
		// hung off the document (order) it links to.
		foreach ($this->auxNodes as $akey => $anode) {
			$nodes[$akey] = $anode;
		}
		foreach ($this->auxEdges as $ae) {
			if (isset($nodes[$ae['from']]) && isset($nodes[$ae['to']])) {
				$edges[] = $ae;
			}
		}

		// Step 3.5 — attach agenda events (calls/emails/meetings) as leaf nodes,
		// each hung off the document it references (or the lead if project-level).
		if ($this->includeEvents) {
			$this->attachEvents($projectId, $nodes, $edges);
		}

		// Step 3.6 — attach phone-call records (pbxcalls) tied to the project.
		if ($this->includeCalls && function_exists('isModEnabled') && isModEnabled('pbxcalls')) {
			$this->attachCalls($projectId, $nodes, $edges);
		}

		// Step 4 — synthesize root edges: any spine node with no upstream spine
		// parent attaches directly to the lead (a standalone proposal, or an order
		// like SO2606-0076 that links only to non-spine objects).
		$indeg = $this->indegrees($edges);
		foreach ($nodes as $key => $node) {
			if ($key === $rootKey) {
				continue;
			}
			if (empty($indeg[$key])) {
				$edges[] = array('from' => $rootKey, 'to' => $key, 'label' => 'LeadtrackerEdgeOpened');
			}
		}

		// Step 4 — topology flags for the renderer.
		$indeg  = $this->indegrees($edges);
		$outdeg = $this->outdegrees($edges);
		foreach ($nodes as $key => &$node) {
			$node['mergePoint'] = (isset($indeg[$key]) && $indeg[$key] > 1);
			$node['fanOut']     = (isset($outdeg[$key]) && $outdeg[$key] > 1);
			$node['deadBranch'] = ($node['state'] === self::STATE_DEAD);
		}
		unset($node);

		return array('root' => $rootKey, 'nodes' => $nodes, 'edges' => $edges);
	}

	/**
	 *  Class file + class name to instantiate per spine type for fetchObjectLinked().
	 *
	 *  @var array
	 */
	private static $loaders = array(
		'propal'   => array('/comm/propal/class/propal.class.php', 'Propal'),
		'commande' => array('/commande/class/commande.class.php', 'Commande'),
		'facture'  => array('/compta/facture/class/facture.class.php', 'Facture'),
		'shipping' => array('/expedition/class/expedition.class.php', 'Expedition'),
	);

	/**
	 *  Seed set: ids of spine documents the project directly owns, by fk_projet and
	 *  by an element link to the project element. Returns ids only — display rows
	 *  are fetched later, after the link walk has settled the full node set.
	 *
	 *  @param  int  $projectId
	 *  @return array  type => [id, ...]
	 */
	private function gatherSeedIds($projectId)
	{
		$pid  = (int) $projectId;
		$ee   = MAIN_DB_PREFIX."element_element";
		$seed = array();

		foreach (self::$types as $type => $cfg) {
			$table = MAIN_DB_PREFIX.$cfg['table'];
			$ids   = array();

			if (!empty($cfg['byProject'])) {
				$sqlA = "SELECT rowid FROM ".$table
					." WHERE fk_projet = ".$pid
					." AND entity IN (".getEntity($cfg['table']).")";
				$res = $this->db->query($sqlA);
				if ($res) {
					while ($o = $this->db->fetch_object($res)) {
						$ids[(int) $o->rowid] = true;
					}
				}
			}

			$tEsc = $this->db->escape($type);
			$sqlB = "SELECT t.rowid FROM ".$table." t"
				." INNER JOIN ".$ee." ee ON ("
				."  (ee.fk_source = ".$pid." AND ee.sourcetype = 'project'"
				."   AND ee.fk_target = t.rowid AND ee.targettype = '".$tEsc."')"
				."  OR (ee.fk_target = ".$pid." AND ee.targettype = 'project'"
				."   AND ee.fk_source = t.rowid AND ee.sourcetype = '".$tEsc."'))"
				." WHERE t.entity IN (".getEntity($cfg['table']).")";
			$res = $this->db->query($sqlB);
			if ($res) {
				while ($o = $this->db->fetch_object($res)) {
					$ids[(int) $o->rowid] = true;
				}
			}

			$seed[$type] = array_keys($ids);
		}

		return $seed;
	}

	/**
	 *  Walk Dolibarr's canonical linked-object graph (fetchObjectLinked) outward
	 *  from the seed documents, collecting every reachable spine document and the
	 *  directed pairs between them. This is the reliable replacement for raw
	 *  element_element id matching: fetchObjectLinked resolves links by element
	 *  TYPE, so the rampant cross-table id collisions cannot produce false edges,
	 *  and non-spine links (supplier orders, receptions, warranty, contracts,
	 *  recurring invoices) are simply ignored.
	 *
	 *  @param  array  $seed  type => [id, ...]
	 *  @return array         [collected (type=>[id=>true]), pairs (list of [[t,id],[t,id]])]
	 */
	private function collectViaLinks($seed)
	{
		$collected = array();
		$pairs     = array();
		$display   = array();
		$visited   = array();
		$queue     = array();
		$this->auxNodes = array();
		$this->auxEdges = array();

		foreach ($seed as $type => $ids) {
			foreach ($ids as $id) {
				$collected[$type][(int) $id] = true;
				$queue[] = array($type, (int) $id);
			}
		}

		$guard = 0;
		while (!empty($queue) && $guard < 500) {
			$guard++;
			list($type, $id) = array_shift($queue);
			$key = $type.':'.$id;
			if (isset($visited[$key]) || !isset(self::$loaders[$type])) {
				continue;
			}
			$visited[$key] = true;

			list($path, $cls) = self::$loaders[$type];
			if (!class_exists($cls)) {
				@dol_include_once($path);
			}
			if (!class_exists($cls)) {
				continue;
			}

			$obj = new $cls($this->db);
			if ($obj->fetch($id) <= 0) {
				continue;
			}
			// Read display fields straight off the loaded object — no second SQL
			// with per-table column-name guesswork (which silently dropped commande
			// and shipping when date_valid/datec didn't exist on those tables).
			$display[$key] = $this->displayFromObject($type, $obj);
			$obj->linkedObjects = array();
			@$obj->fetchObjectLinked();
			if (empty($obj->linkedObjects) || !is_array($obj->linkedObjects)) {
				continue;
			}

			foreach ($obj->linkedObjects as $linktype => $list) {
				if (!is_array($list)) {
					continue;
				}
				$nt = $this->normalizeType($linktype);

				// Spine documents: build the deal topology and keep walking.
				if (isset(self::$types[$nt])) {
					foreach ($list as $lo) {
						if (!is_object($lo) || empty($lo->id)) {
							continue;
						}
						$lid = (int) $lo->id;
						$pairs[] = array(array($type, $id), array($nt, $lid));
						if (empty($collected[$nt][$lid])) {
							$collected[$nt][$lid] = true;
							$queue[] = array($nt, $lid);
						}
					}
					continue;
				}

				// Aux records (warranty / request): leaf cards under this document.
				// Captured but not walked further.
				if ($this->includeWarranty && isset(self::$auxTypes[$linktype])) {
					foreach ($list as $lo) {
						if (!is_object($lo) || empty($lo->id)) {
							continue;
						}
						$akey = $linktype.':'.(int) $lo->id;
						if (!isset($this->auxNodes[$akey])) {
							$this->auxNodes[$akey] = $this->buildAuxNode($linktype, $lo);
						}
						$this->auxEdges[] = array('from' => $type.':'.$id, 'to' => $akey, 'label' => 'LeadtrackerEdgeAux');
					}
				}
				// Everything else (supplier orders, receptions, contracts, …) ignored.
			}
		}

		return array($collected, $pairs, $display);
	}

	/**
	 *  Build a leaf node for an aux record (warranty claim / service request)
	 *  discovered during the link walk. Reads ref/status/date off the linked
	 *  object's properties.
	 *
	 *  @param  string  $linktype  fetchObjectLinked element key
	 *  @param  object  $obj
	 *  @return array
	 */
	private function buildAuxNode($linktype, $obj)
	{
		$cfg  = self::$auxTypes[$linktype];
		$id   = (int) $obj->id;
		$disp = $this->displayFromObject('aux', $obj);

		return array(
			'key'        => $linktype.':'.$id,
			'type'       => 'aux',
			'auxKind'    => $cfg['kind'],
			'id'         => $id,
			'ref'        => (string) $disp['ref'],
			'title'      => '',
			'label'      => $cfg['label'],
			'state'      => self::STATE_EVENT,
			'sub'        => '',
			'date'       => $disp['date'],
			'amount'     => null,
			'url'        => DOL_URL_ROOT.$cfg['card'].$id,
			'mergePoint' => false,
			'fanOut'     => false,
			'deadBranch' => false,
		);
	}

	/**
	 *  Extract uniform display fields from a loaded Dolibarr document object. Reads
	 *  object PROPERTIES (already hydrated by fetch()), so it never depends on a
	 *  table having a particular column name. Dates after fetch() are unix ints.
	 *
	 *  @param  string  $type
	 *  @param  object  $obj
	 *  @return array   ['id','ref','status','date','amount','paye']
	 */
	private function displayFromObject($type, $obj)
	{
		// Status: prefer ->status, fall back to deprecated ->statut.
		if (isset($obj->status) && $obj->status !== null && $obj->status !== '') {
			$status = (int) $obj->status;
		} elseif (isset($obj->statut) && $obj->statut !== null && $obj->statut !== '') {
			$status = (int) $obj->statut;
		} else {
			$status = 0;
		}

		// Date: first populated of validation → creation → generic, as a unix int.
		$date = null;
		foreach (array('date_validation', 'date_valid', 'date_creation', 'datec', 'date', 'date_commande') as $f) {
			if (isset($obj->$f) && !empty($obj->$f) && is_numeric($obj->$f)) {
				$date = (int) $obj->$f;
				break;
			}
		}

		// Amount: total incl. tax for commercial docs; shipments carry none.
		$amount = null;
		if ($type !== 'shipping' && isset($obj->total_ttc) && $obj->total_ttc !== '' && $obj->total_ttc !== null) {
			$amount = (float) $obj->total_ttc;
		}

		$paye = ($type === 'facture' && isset($obj->paye)) ? (int) $obj->paye : null;

		return array(
			'id'     => (int) $obj->id,
			'ref'    => (string) $obj->ref,
			'status' => $status,
			'date'   => $date,
			'amount' => $amount,
			'paye'   => $paye,
		);
	}

	/**
	 *  Normalize a Dolibarr linked-object element key into a spine type.
	 *
	 *  @param  string  $element
	 *  @return string
	 */
	private function normalizeType($element)
	{
		$map = array(
			'expedition' => 'shipping',
			'shipping'   => 'shipping',
			'order'      => 'commande',
			'invoice'    => 'facture',
			'proposal'   => 'propal',
		);
		return isset($map[$element]) ? $map[$element] : $element;
	}

	/**
	 *  Attach agenda events (calls, emails, meetings, faxes, other manual events)
	 *  as leaf nodes. Each event hangs off the specific document it references
	 *  (actioncomm.fk_element/elementtype), or off the lead when it is only tied to
	 *  the project. System/auto events (project create, order delete, …) are
	 *  excluded via a positive code allowlist so the tree is not flooded.
	 *
	 *  @param  int    $projectId
	 *  @param  array  &$nodes  (mutated — event nodes added)
	 *  @param  array  &$edges  (mutated — anchor->event edges added)
	 *  @return void
	 */
	private function attachEvents($projectId, &$nodes, &$edges)
	{
		$pid = (int) $projectId;

		// Bucket our document ids BY TYPE. A coarse "fk_element IN (allIds)" match
		// is unsafe: element ids collide across tables, so an event about another
		// deal's commande #69 would match our propal #69 and then fall back onto
		// the lead. Match (elementtype, fk_element) precisely per type instead.
		$idsByType = array();
		foreach ($nodes as $n) {
			if ($n['type'] !== 'project' && $n['type'] !== 'event') {
				$idsByType[$n['type']][] = (int) $n['id'];
			}
		}
		$elementAliases = array(
			'propal'   => array('propal', 'proposal'),
			'commande' => array('commande', 'order'),
			'facture'  => array('facture', 'invoice'),
			'shipping' => array('shipping', 'expedition'),
		);
		$docConds = array();
		foreach ($idsByType as $type => $ids) {
			if (empty($ids)) {
				continue;
			}
			$aliases = isset($elementAliases[$type]) ? $elementAliases[$type] : array($type);
			$quoted  = array();
			foreach ($aliases as $a) {
				$quoted[] = "'".$this->db->escape($a)."'";
			}
			$docConds[] = "(a.elementtype IN (".implode(',', $quoted).")"
				." AND a.fk_element IN (".implode(',', array_map('intval', $ids))."))";
		}

		// actioncomm legacy quirk: PK is `id`, not `rowid`. An event qualifies if it
		// is tied to the project itself, or precisely to one of our documents. In
		// broad mode, also anything tied to the deal's CUSTOMER (fk_soc) — those are
		// flagged customer-level below.
		$where = "a.fk_project = ".$pid;
		if (!empty($docConds)) {
			$where = "(a.fk_project = ".$pid." OR ".implode(" OR ", $docConds).")";
		}
		$soc = 0;
		if ($this->broad) {
			$soc = $this->projectSocId($pid);
			if ($soc > 0) {
				$where = "(".$where." OR a.fk_soc = ".$soc.")";
			}
		}

		// Positive allowlist: real human touchpoints (calls, emails, SMS, meetings,
		// faxes), not system gear events.
		$codeFilter = "(a.code LIKE '%SENTBYMAIL' OR a.code LIKE '%SENTBYSMS'"
			." OR a.code IN ('AC_TEL', 'AC_RDV', 'AC_EMAIL', 'AC_FAX', 'AC_SMS', 'AC_OTH'))";

		$sql = "SELECT a.id, a.label, a.code, a.fk_element, a.elementtype, a.fk_project, a.datep, a.datec"
			." FROM ".MAIN_DB_PREFIX."actioncomm a"
			." WHERE ".$where
			." AND ".$codeFilter
			." AND a.entity IN (".getEntity('actioncomm').")"
			." ORDER BY (a.datep IS NULL) ASC, a.datep ASC, a.id ASC";
		$res = $this->db->query($sql);
		if (!$res) {
			return;
		}

		while ($e = $this->db->fetch_object($res)) {
			$eid = (int) $e->id;
			$key = 'event:'.$eid;
			if (isset($nodes[$key])) {
				continue;
			}

			// Anchor: the specific doc it references (precise type+id, collision
			// safe) when present and in the graph, else the lead.
			$anchor   = 'project:'.$pid;
			$docMatch = false;
			if (!empty($e->fk_element) && !empty($e->elementtype)) {
				$cand = $this->normalizeType($e->elementtype).':'.(int) $e->fk_element;
				if (isset($nodes[$cand])) {
					$anchor   = $cand;
					$docMatch = true;
				}
			}

			$node = $this->buildEventNode($eid, $e);
			// Customer-level (broad) if it reached us only via fk_soc — neither
			// tied to this project nor to one of its documents.
			$node['broad'] = (!$docMatch && (int) $e->fk_project !== $pid);
			$nodes[$key] = $node;
			$edges[] = array('from' => $anchor, 'to' => $key, 'label' => 'LeadtrackerEdgeEvent');
		}
	}

	/**
	 *  Build a leaf node for an agenda event.
	 *
	 *  @param  int       $id
	 *  @param  stdClass  $e   actioncomm row
	 *  @return array
	 */
	private function buildEventNode($id, $e)
	{
		$kind = $this->eventKind($e->code);
		$date = null;
		if (!empty($e->datep)) {
			$date = $this->db->jdate($e->datep);
		} elseif (!empty($e->datec)) {
			$date = $this->db->jdate($e->datec);
		}

		return array(
			'key'        => 'event:'.$id,
			'type'       => 'event',
			'id'         => $id,
			'eventKind'  => $kind,
			'ref'        => isset($e->label) ? trim((string) $e->label) : '',
			'title'      => '',
			'label'      => 'LeadtrackerNodeEvent',
			'state'      => self::STATE_EVENT,
			'sub'        => '',
			'date'       => ($date !== null) ? (int) $date : null,
			'amount'     => null,
			'url'        => DOL_URL_ROOT.'/comm/action/card.php?id='.$id,
			'broad'      => false,
			'mergePoint' => false,
			'fanOut'     => false,
			'deadBranch' => false,
		);
	}

	/**
	 *  Map an actioncomm code to a coarse event kind.
	 *
	 *  @param  string  $code
	 *  @return string  call|email|meeting|fax|other
	 */
	private function eventKind($code)
	{
		$code = (string) $code;
		if ($code === 'AC_TEL') {
			return 'call';
		}
		if ($code === 'AC_RDV') {
			return 'meeting';
		}
		if ($code === 'AC_FAX') {
			return 'fax';
		}
		if ($code === 'AC_SMS' || strpos($code, 'SENTBYSMS') !== false) {
			return 'sms';
		}
		if ($code === 'AC_EMAIL' || strpos($code, 'SENTBYMAIL') !== false) {
			return 'email';
		}
		return 'other';
	}

	/**
	 *  Attach phone-call records (pbxcalls module) as leaf nodes under the lead.
	 *
	 *  Precise scope (default): only calls tagged to THIS project (fk_project).
	 *  Broad scope ($this->broad): also the deal customer's calls (fk_soc), since the
	 *  module phone-matches to a thirdparty and may not set fk_project. Calls that
	 *  matched only by customer (not the project) are flagged 'broad' so the
	 *  renderer can mark them as customer-level rather than deal-specific.
	 *
	 *  @param  int    $projectId
	 *  @param  array  &$nodes
	 *  @param  array  &$edges
	 *  @return void
	 */
	private function attachCalls($projectId, &$nodes, &$edges)
	{
		$pid     = (int) $projectId;
		$rootKey = 'project:'.$pid;

		$scope = "c.fk_project = ".$pid;
		if ($this->broad) {
			$soc = $this->projectSocId($pid);
			if ($soc > 0) {
				$scope = "(c.fk_project = ".$pid." OR c.fk_soc = ".$soc.")";
			}
		}

		$sql = "SELECT c.rowid, c.ref, c.label, c.status, c.call_start, c.date_creation,"
			." c.direction, c.disposition, c.duration_sec, c.caller_name, c.fk_project"
			." FROM ".MAIN_DB_PREFIX."pbxcalls_call c"
			." WHERE ".$scope
			." AND c.entity IN (".getEntity('pbxcalls_call').")"
			." ORDER BY (c.call_start IS NULL) ASC, c.call_start ASC, c.rowid ASC";
		$res = $this->db->query($sql);
		if (!$res) {
			return; // module table absent / query error — skip silently
		}

		while ($c = $this->db->fetch_object($res)) {
			$cid = (int) $c->rowid;
			$key = 'call:'.$cid;
			if (isset($nodes[$key])) {
				continue;
			}
			$node = $this->buildCallNode($cid, $c);
			$node['broad'] = ((int) $c->fk_project !== $pid);
			$nodes[$key] = $node;
			$edges[] = array('from' => $rootKey, 'to' => $key, 'label' => 'LeadtrackerEdgeCall');
		}
	}

	/**
	 *  The thirdparty (customer) id of a project, or 0.
	 *
	 *  @param  int  $pid
	 *  @return int
	 */
	private function projectSocId($pid)
	{
		$sql = "SELECT fk_soc FROM ".MAIN_DB_PREFIX."projet"
			." WHERE rowid = ".(int) $pid." AND entity IN (".getEntity('projet').")";
		$res = $this->db->query($sql);
		if ($res && ($o = $this->db->fetch_object($res))) {
			return (int) $o->fk_soc;
		}
		return 0;
	}

	/**
	 *  Build a leaf node for a phone-call record.
	 *
	 *  @param  int       $id
	 *  @param  stdClass  $c   pbxcalls_call row
	 *  @return array
	 */
	private function buildCallNode($id, $c)
	{
		$date = null;
		if (!empty($c->call_start)) {
			$date = $this->db->jdate($c->call_start);
		} elseif (!empty($c->date_creation)) {
			$date = $this->db->jdate($c->date_creation);
		}

		$dir = strtolower((string) $c->direction);
		$dir = (strpos($dir, 'in') === 0) ? 'in' : ((strpos($dir, 'out') === 0) ? 'out' : '');

		$label = trim((string) $c->caller_name);
		if ($label === '') {
			$label = trim((string) $c->ref);
		}

		return array(
			'key'         => 'call:'.$id,
			'type'        => 'call',
			'id'          => $id,
			'callDir'     => $dir,
			'disposition' => (string) $c->disposition,
			'duration'    => (int) $c->duration_sec,
			'ref'         => $label,
			'title'       => '',
			'label'       => 'LeadtrackerNodeCall',
			'state'       => self::STATE_EVENT,
			'sub'         => '',
			'date'        => ($date !== null) ? (int) $date : null,
			'amount'      => null,
			'url'         => DOL_URL_ROOT.'/custom/pbxcalls/call_card.php?id='.$id,
			'broad'       => false,
			'mergePoint'  => false,
			'fanOut'      => false,
			'deadBranch'  => false,
		);
	}

	/**
	 *  Turn the undirected linked-object pairs into directed, deduplicated spine
	 *  edges. Direction is canonicalized by rank (parent = lower rank) regardless
	 *  of which way fetchObjectLinked reported the link. Pairs whose type
	 *  combination is not a meaningful spine relationship are dropped.
	 *
	 *  @param  array  $pairs  List of [[type,id],[type,id]]
	 *  @param  array  $nodes  Node map (keys "type:id") — both endpoints must exist
	 *  @return array          List of ['from','to','label']
	 */
	private function edgesFromPairs($pairs, $nodes)
	{
		$edges = array();
		$seen  = array();
		foreach ($pairs as $p) {
			list($a, $b) = $p;
			if (!isset(self::$types[$a[0]]) || !isset(self::$types[$b[0]])) {
				continue;
			}
			$aKey = $a[0].':'.$a[1];
			$bKey = $b[0].':'.$b[1];
			if (!isset($nodes[$aKey]) || !isset($nodes[$bKey])) {
				continue;
			}

			// Orient parent -> child by rank.
			if (self::$types[$a[0]]['rank'] <= self::$types[$b[0]]['rank']) {
				list($parent, $child) = array($a, $b);
			} else {
				list($parent, $child) = array($b, $a);
			}
			$pairKey = $parent[0].'>'.$child[0];
			if (!isset(self::$spinePairs[$pairKey])) {
				continue; // not a meaningful spine relationship
			}
			$fromKey = $parent[0].':'.$parent[1];
			$toKey   = $child[0].':'.$child[1];
			$edgeKey = $fromKey.'->'.$toKey;
			if (isset($seen[$edgeKey]) || $fromKey === $toKey) {
				continue;
			}
			$seen[$edgeKey] = true;
			$edges[] = array('from' => $fromKey, 'to' => $toKey, 'label' => self::$spinePairs[$pairKey]);
		}
		return $edges;
	}

	/**
	 *  In-degree per node key.
	 *
	 *  @param  array  $edges
	 *  @return array  key => count
	 */
	private function indegrees($edges)
	{
		$d = array();
		foreach ($edges as $e) {
			$d[$e['to']] = (isset($d[$e['to']]) ? $d[$e['to']] : 0) + 1;
		}
		return $d;
	}

	/**
	 *  Out-degree per node key.
	 *
	 *  @param  array  $edges
	 *  @return array  key => count
	 */
	private function outdegrees($edges)
	{
		$d = array();
		foreach ($edges as $e) {
			$d[$e['from']] = (isset($d[$e['from']]) ? $d[$e['from']] : 0) + 1;
		}
		return $d;
	}

	/**
	 *  Build the root (lead) node from the project row.
	 *
	 *  @param  int  $projectId
	 *  @return array
	 */
	private function buildRootNode($projectId)
	{
		$pid = (int) $projectId;
		$ref = '';
		$title = '';
		$amount = null;
		$sql = "SELECT ref, title, opp_amount FROM ".MAIN_DB_PREFIX."projet"
			." WHERE rowid = ".$pid." AND entity IN (".getEntity('projet').")";
		$res = $this->db->query($sql);
		if ($res && ($row = $this->db->fetch_object($res))) {
			$ref    = (string) $row->ref;
			$title  = (string) $row->title;
			$amount = ($row->opp_amount !== null && $row->opp_amount !== '') ? (float) $row->opp_amount : null;
		}
		return array(
			'key'        => 'project:'.$pid,
			'type'       => 'project',
			'id'         => $pid,
			'ref'        => $ref,
			'title'      => $title,
			'label'      => 'LeadtrackerNodeLead',
			'state'      => self::STATE_ACTIVE,
			'sub'        => '',
			'date'       => null,
			'amount'     => $amount,
			'url'        => DOL_URL_ROOT.'/projet/card.php?id='.$pid,
			'mergePoint' => false,
			'fanOut'     => false,
			'deadBranch' => false,
		);
	}

	/**
	 *  Build a document node from the display fields read off its object.
	 *
	 *  @param  string  $type  Normalized spine type
	 *  @param  array   $disp  ['id','ref','status','date','amount','paye']
	 *  @return array
	 */
	private function buildDocNode($type, $disp)
	{
		$cfg    = self::$types[$type];
		$id     = (int) $disp['id'];
		$status = (int) $disp['status'];
		$paye   = ($disp['paye'] !== null) ? (int) $disp['paye'] : null;

		list($state, $subKey) = $this->classify($type, $status, $paye);

		$date   = ($disp['date'] !== null) ? (int) $disp['date'] : null;
		$amount = ($disp['amount'] !== null) ? (float) $disp['amount'] : null;

		return array(
			'key'    => $type.':'.$id,
			'type'   => $type,
			'id'     => $id,
			'ref'    => (string) $disp['ref'],
			'title'  => '',
			'label'  => $this->typeLabelKey($type),
			'state'  => $state,
			'sub'    => $subKey,
			'date'   => $date,
			'amount' => $amount,
			'url'    => DOL_URL_ROOT.$cfg['card'].$id,
		);
	}

	/**
	 *  Classify a document's native status into the deal-graph state vocabulary.
	 *
	 *  Native status values (Dolibarr v22):
	 *   - propal:     0 draft, 1 validated, 2 signed, 3 not-signed, 4 billed
	 *   - commande:   0 draft, 1 validated, 2 in-process, 3 closed, -1 cancelled
	 *   - facture:    0 draft, 1 validated, 2 abandoned; paye flag = paid
	 *   - expedition: 0 draft, 1 validated, 2 closed/processed
	 *
	 *  @param  string    $type
	 *  @param  int       $status  fk_statut
	 *  @param  int|null  $paye    facture paid flag (null for non-invoices)
	 *  @return array               [state, sub-lang-key]
	 */
	private function classify($type, $status, $paye)
	{
		switch ($type) {
			case 'propal':
				if ($status === 2) return array(self::STATE_DONE,   'LeadtrackerSubSigned');
				if ($status === 4) return array(self::STATE_DONE,   'LeadtrackerSubBilled');
				if ($status === 3) return array(self::STATE_DEAD,   'LeadtrackerSubRefused');
				if ($status === 1) return array(self::STATE_ACTIVE, 'LeadtrackerSubValidated');
				return array(self::STATE_PENDING, 'LeadtrackerSubDraft');

			case 'commande':
				if ($status === -1) return array(self::STATE_DEAD,   'LeadtrackerSubCancelled');
				if ($status === 3)  return array(self::STATE_DONE,   'LeadtrackerSubClosed');
				if ($status === 2)  return array(self::STATE_ACTIVE, 'LeadtrackerSubInProcess');
				if ($status === 1)  return array(self::STATE_ACTIVE, 'LeadtrackerSubValidated');
				return array(self::STATE_PENDING, 'LeadtrackerSubDraft');

			case 'facture':
				if ($paye === 1)   return array(self::STATE_DONE,   'LeadtrackerSubPaid');
				if ($status === 2) return array(self::STATE_DEAD,   'LeadtrackerSubAbandoned');
				if ($status === 1) return array(self::STATE_ACTIVE, 'LeadtrackerSubUnpaid');
				return array(self::STATE_PENDING, 'LeadtrackerSubDraft');

			case 'shipping':
				if ($status === 2) return array(self::STATE_DONE,   'LeadtrackerSubProcessed');
				if ($status === 1) return array(self::STATE_ACTIVE, 'LeadtrackerSubValidated');
				return array(self::STATE_PENDING, 'LeadtrackerSubDraft');
		}
		return array(self::STATE_PENDING, 'LeadtrackerSubDraft');
	}

	/**
	 *  Lang key for a spine type's display label.
	 *
	 *  @param  string  $type
	 *  @return string
	 */
	private function typeLabelKey($type)
	{
		$map = array(
			'propal'   => 'LeadtrackerNodeProposal',
			'commande' => 'LeadtrackerNodeOrder',
			'facture'  => 'LeadtrackerNodeInvoice',
			'shipping' => 'LeadtrackerNodeShipment',
		);
		return isset($map[$type]) ? $map[$type] : $type;
	}
}
