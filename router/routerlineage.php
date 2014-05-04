<?php
namespace Rodokmen;
use \R;


RouterBase::regRouter('Rodokmen\RouterLineage');

class RouterLineage extends RouterBase
{
	public function setup($app)
	{
		$app->group('/ajax', $this->checkAjax(), function () use ($app)
		{
			// Linage JSON route:
			$app->get('/lineage', $this->authRole(Role::AllMembers), $this->contentJson(), function() use ($app)
			{
				$l = new Lineage();
				echo $l->toJson();   // JSON representation to display using Cytoscape
			});


			// Person details for sidebar:
			$app->get('/person/:id', $this->authRole(Role::AllMembers), function($id) use ($app)
			{
				$bean = self::getBean($id, new Person(), $app);
				$app->view->setData($bean->sidebarData());
				$app->render('ajax/sidebar-person.html');
			});

			// Edit existing person:
			$app->get('/person/edit/:id', $this->authRole(Role::AllContrib), function($id) use ($app)
			{
				$bean = self::getBean($id, new Person(), $app);
				$app->view->setData($bean->infoData());
				$app->view->setData('action', $app->urlFor('person-edit-p'));
				$app->render('ajax/form-tree-person.html');
			})->name('person-edit');
			$app->post('/person/edit', $this->authRole(Role::AllContrib), function() use ($app)
			{
				$rq = $app->request;
				$person = new Person();
				$bean = self::getBean($rq->post('rdk_id'), $person, $app);
				$bean->edit($rq);
				$person->store($bean);
				// FIXME: log
			})->name('person-edit-p');

			// Delete a person:
			$app->get('/person/delete/:id', $this->authRole(Role::AllContrib), function($id) use ($app)
			{
				$bean = self::getBean($id, new Person(), $app);
				$app->view->setData(array(
					'id' => $id,
					'todelete' => $bean->canBeDeleted(),
					'action' => $app->urlFor('person-delete-p')
				));
				$app->render('ajax/form-tree-delete.html');
			})->name('person-delete');
			$app->post('/person/delete', $this->authRole(Role::AllContrib), function() use ($app)
			{
				$p = self::getBean($app->request->post('rdk_id'), new Person(), $app);
				$delbeans = $p->deleteBeans();
				if (empty($delbeans)) $app->halt(403);
				else DB::transaction(DB::data, function() use ($delbeans)
				{
					R::trashAll($delbeans);
					// FIXME: log
				});
			})->name('person-delete-p');


			// Marriage details for sidebar
			$app->get('/marriage/:id', $this->authRole(Role::AllMembers), function($id) use ($app)
			{
				$bean = self::getBean($id, new Marriage(), $app);
				$app->view->setData($bean->sidebarData());
				$app->render('ajax/sidebar-marriage.html');
			});

			// TODO: edit marriage

			// Add a new child to a marriage
			$app->get('/marriage/newchild/:id', $this->authRole(Role::AllContrib), function($id) use ($app)
			{
				$bean = self::getBean($id, new Marriage(), $app);
				$app->view->setData('id', $id);
				$app->view->setData('action', $app->urlFor('marriage-newchild-p'));
				$app->render('ajax/form-tree-person.html');
			})->name('marriage-newchild');
			$app->post('/marriage/newchild', $this->authRole(Role::AllContrib), function() use ($app)
			{
				DB::transaction(DB::data, function() use ($app)
				{
					$rq = $app->request;
					$m = self::getBean($rq->post('rdk_id'), new Marriage(), $app);

					$pod_p = new Person();
					$pod_r = new Relation();

					$p = $pod_p->setupNew();
					$p->edit($rq);
					$r = $pod_r->relate($p, $m, 'child');

					$pod_p->store($p);
					$pod_r->store($r);

					// FIXME: log
				});
			})->name('marriage-newchild-p');

			// New marriage w/ person
			$app->get('/marriage/new/withperson/:id', $this->authRole(Role::AllContrib), function($id) use ($app)
			{
				$app->view->setData('id', $id);
				$app->view->setData('action', $app->urlFor('marriage-new-withperson-p'));
				$app->render('ajax/form-tree-person.html');
			})->name('marriage-new-withperson');
			$app->post('/marriage/new/withperson', $this->authRole(Role::AllContrib), function() use ($app)
			{
				// FIXME: log
				DB::transaction(DB::data, function() use ($app)
				{
					$rq = $app->request;
					$pod_p = new Person();
					$pod_m = new Marriage();
					$pod_r = new Relation();

					$p = self::getBean($rq->post('rdk_id'), $pod_p, $app);
					$np = $pod_p->setupNew();
					$np->edit($rq);
					$m = $pod_m->setupNew();
					$r1 = $pod_r->relate($p,  $m, 'parent');
					$r2 = $pod_r->relate($np, $m, 'parent');

					$pod_p->store($np);
					$pod_m->store($m);
					$pod_r->store($r1);
					$pod_r->store($r2);

					// FIXME: log
				});
			})->name('marriage-new-withperson-p');

			// New parent marriage for a child
			$app->get('/marriage/new/forchild/:id', $this->authRole(Role::AllContrib), function($id) use ($app)
			{
				$app->view->setData('id', $id);
				$app->view->setData('action', $app->urlFor('marriage-new-forchild-p'));
				$app->render('ajax/form-tree-parents.html');
			})->name('marriage-new-forchild');
			$app->post('/marriage/new/forchild', $this->authRole(Role::AllContrib), function() use ($app)
			{
				DB::transaction(DB::data, function() use ($app)
				{
					$rq = $app->request;
					$pod_p = new Person();
					$pod_m = new Marriage();
					$pod_r = new Relation();

					$c = self::getBean($rq->post('rdk_id'), $pod_p, $app);
					if ($c->parents()) $app->halt(403);  // Checks whether this person already has parents

					$p1 = $pod_p->setupNew();
					$p1->setNames($rq->post('rdk_p1_firstname'), $rq->post('rdk_p1_lastname'));
					$p2 = $pod_p->setupNew();
					$p2->setNames($rq->post('rdk_p2_firstname'), $rq->post('rdk_p2_lastname'));
					$m = $pod_m->setupNew();
					$rp1 = $pod_r->relate($p1, $m, 'parent');
					$rp2 = $pod_r->relate($p2, $m, 'parent');
					$rc = $pod_r->relate($c, $m, 'child');

					$pod_p->store($p1);
					$pod_p->store($p2);
					$pod_m->store($m);
					$pod_r->store($rp1);
					$pod_r->store($rp2);
					$pod_r->store($rc);

					// FIXME: log
				});
			})->name('marriage-new-forchild-p');

			// Delete a marriage:
			$app->get('/marriage/delete/:id', $this->authRole(Role::AllContrib), function($id) use ($app)
			{
				$bean = self::getBean($id, new Marriage(), $app);
				$app->view->setData(array(
					'id' => $id,
					'todelete' => $bean->canBeDeleted(),
					'action' => $app->urlFor('marriage-delete-p')
				));
				$app->render('ajax/form-tree-delete.html');
			})->name('marriage-delete');
			$app->post('/marriage/delete', $this->authRole(Role::AllContrib), function() use ($app)
			{
				$m = self::getBean($app->request->post('rdk_id'), new Marriage(), $app);
				$delbeans = $m->deleteBeans();
				if (empty($delbeans)) $app->halt(403);
				else DB::transaction(DB::data, function() use ($delbeans)
				{
					R::trashAll($delbeans);
					// FIXME: log
				});
			})->name('marriage-delete-p');
		});
	}

	public function __construct($app)
	{
		parent::__construct($app);
	}
};

