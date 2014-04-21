<?php
namespace Rodokmen;
use \R;


class Person extends Pod
{
	public function __construct() { parent::__construct('person'); }
}

class ModelPerson extends \RedBean_SimpleModel
{
	private $_name_new;

	public static function makeLinks($persons)
	{
		$ret = array();
		foreach ($persons as $p) $ret[] = $p->linkData();
		return $ret;
	}

	public function dispense()
	{
		$this->_name_new = R::dispense('name');
		$this->bean->via('relation');
	}

	public function open() { $this->bean->via('relation'); }


	public function relations($role = false)
	{
		if ($role) $ret = $this->bean->withCondition('relation.role = ?', array($role))->ownRelationList;
		else $ret = $this->bean->ownRelationList;
		return \array_values($ret);
	}

	public function names()
	{
		/* Names TODO:
		 * Names are going to need some serious thought & design. What might be needed:
		 * 1. Past names (incl. birth name, arbitrary number of marriages, etc.)
		 * 2. Middle names
		 * 3. Godfather?
		 * Also don't forget to modify deletion code
		 */

		return \array_values($this->bean->xownNameList);
	}

	public function setNames($first, $last)
	{
		// For now, only one name is supported
		if (count($this->bean->xownNameList) == 0)
		{
			$name = $this->_name_new;
			$this->bean->xownNameList[] = $name;
		} else
		{
			$name = \reset($this->bean->xownNameList);
		}
		$name->first = $first;
		$name->last  = $last;
	}

	public function displayName($sep = ' ')
	{
		$n = $this->names()[0];
		return $n->first.$sep.$n->last;
	}

	public function parentMarriage()
	{
		$m = $this->bean->withCondition('relation.role = ?', array('child'))->sharedMarriageList;
		return \reset($m);
	}

	public function parents()
	{
		$m = $this->parentMarriage();
		if (!$m) return array();
		else return $m->spouses();
	}

	public function marriages()
	{
		$ms = $this->bean->withCondition('relation.role = ?', array('parent'))->sharedMarriageList;
		return \array_values($ms);
	}

	public function children()
	{
		$ret = array();
		$ms = $this->marriages();
		foreach ($ms as $m) $ret = \array_merge($ret, $m->children());
		return $ret;
	}

	public function cyId()
	{
		return 'p'.$this->bean->id;
	}

	public function cyNode()
	{
		return array(
			'data' => array(
				'id' => $this->cyId(),
				'name' => $this->displayName("\n"),
				'oid' => $this->bean->id),
			'classes' => 'p'
		);
	}

	public function linkData()
	{
		return array('dname' => $this->displayName(), 'cyid' => $this->cyId());
	}

	public function infoData()
	{
		$names = $this->names();
		$name = array(
			'first' => $names[0]->first,
			'last' => $names[0]->last
		);

		$ret = array(
			'id' => $this->bean->id,
			'dname' => $this->displayName(),
			'name' => $name
		);

		return $ret;
	}

	public function sidebarData()
	{
		$pm = $this->parentMarriage();

		$ret = array(
			'p' => $this->infoData(),
			'parentMarriage' => $pm ? $pm->cyId() : '',
			'parents' => self::makeLinks($this->parents()),
			'children' => self::makeLinks($this->children())
		);

		$ret['marriages'] = array();
		$ms = $this->marriages();
		foreach ($ms as $m)
		{
			$ret['marriages'][] = array(
				'cyid' => $m->cyId(),
				'spouse' => $m->spouseTo($this->bean)->linkData(),
				'children' => self::makeLinks($m->children())
			);
		}

		return $ret;
	}

	public function edit($rq)
	{
		$bean = $this->bean;

		$this->setNames($rq->post('rdk_firstname'), $rq->post('rdk_lastname'));
	}

	public function canBeDeleted()
	{
		// If this person can be deleted, returns an array of perons which would be deleted along _and_ this one
		// otherwise an empty array

		$marriages = $this->marriages();
		switch (count($marriages))
		{
			case 0:  return array($this->bean->linkData());               // This person is a leaf node
			case 1:  return $marriages[0]->canBeDeleted($this->bean->id); // This person is member of a marriage, delegate to the marriage
			default: return array();                                      // several marriages, can't be deleted
		}
	}

	public function ownBeans()
	{
		return \array_merge($this->relations(), $this->names(), array($this->bean));
	}

	public function deleteBeans()
	{
		// Returns beans to delete along with deletion of this bean

		$marriages = $this->marriages();
		switch (count($marriages))
		{
			case 0:  return $this->ownBeans();
			case 1:  return $marriages[0]->deleteBeans($this->bean->id);
			default: return array();
		}
	}

}