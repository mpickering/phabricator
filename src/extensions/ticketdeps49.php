<?php

final class EditTaskDependenciesAPIMethod extends ManiphestConduitAPIMethod {

  public function getAPIMethodName() {
    return 'maniphest.editdependencies';
  }

  public function getMethodDescription() {
    return pht('Edit the dependencies for differential tasks.');
  }

  protected function defineParamTypes() {
    return [
      'taskPHID'            => 'required id',
      'dependsOnTasks'      => 'optional list<phid>',
      'blockingTasks'       => 'optional list<phid>',
      'dependsOnCommits'    => 'optional list<string>',
      'dependsOnDiffs'      => 'optional list<string>',
      'author'              => 'phid'
    ];
  }

  protected function defineReturnType() {
    return 'nonempty dict';
  }

  protected function defineErrorTypes() {
    return [
      'ERR_GRAPH_CYCLE'   => pht(
        'The relationships between objects described in this request would creates a cycle in '.
        'their dependency graph.'),
      'ERR_BAD_REVISION'  => pht(
        'The specified revision PHID does not correspond to an existing differential revision.'),
    ];
  }

  protected function execute(ConduitAPIRequest $request) {
    $userphid = $request->getValue('author');

    $query = id(new PhabricatorPeopleQuery())
      ->setViewer($request->getUser())
      ->needProfileImage(true)
      ->needAvailability(true)
      ->withPHIDs(array($userphid));

    $users = $query->execute();

    $user = array_values($users)[0];


    $phid = $request->getValue('taskPHID');
    $attach_type = ManiphestTaskPHIDType::TYPECONST;

    $revision = (new ManiphestTaskQuery)
      ->setViewer($user)
      ->withPHIDs([$phid])
      ->executeOne();

    if (!$revision) {
      throw new ConduitException('ERR_BAD_REVISION');
    }

    $edge_type = ManiphestTaskDependsOnTaskEdgeType::EDGECONST;
    $redge_type = ManiphestTaskDependedOnByTaskEdgeType::EDGECONST;
    $cedge_type = ManiphestTaskHasCommitEdgeType::EDGECONST;
    $dedge_type = ManiphestTaskHasRevisionEdgeType::EDGECONST;


    if ($request-> getValue('dependsOnCommits')) {
      //commits
      $cquery = id(new DiffusionCommitQuery())
        ->setViewer($user)
        ->needCommitData(true)
        ->withIdentifiers($request->getValue('dependsOnCommits'));

      $pager = $this->newPager($request);
      $commits = $cquery->executeWithCursorPager($pager);
      $commits = array_map(function($obj){ return $obj->getPHID();}, $commits);
      }
      else {
       $commits = array();
      }

    // Tasks
    if ($request-> getValue('dependsOnTasks')) {
      $phids = $request->getValue('dependsOnTasks', []);
      //throw new ConduitException($phids);
      $add_phids = $phids['add'];
      $rem_phids = $phids['remove'];
    }
    else {
      $add_phids = array();
      $rem_phids = array();
    }

    if ($request-> getValue('blockingTasks')) {
      $bphids = $request->getValue('blockingTasks', []);

      $badd_phids = $bphids['add'];
      $brem_phids = $bphids['remove'];
    }
    else {
      $badd_phids = array();
      $brem_phids = array();
    }




    // Diffs
    if ($request-> getValue('dependsOnDiffs')['add']) {
      $dquery = id(new DifferentialRevisionQuery())
		    -> setViewer($user)
        -> needRelationships(true)
        -> needCommitPHIDs(true)
        -> needDiffIDs(true)
        -> needActiveDiffs(true)
        -> needHashes(true)
		    -> withIDs($request->getValue('dependsOnDiffs')['add']);

        $newrevisions = $dquery->execute();
        $newrevisions = array_map(function($obj){ return $obj->getPHID();}, $newrevisions);
    }
    else {
      $newrevisions = array();
    }

    if ($request-> getValue('dependsOnDiffs')['remove']) {
      $dquery = id(new DifferentialRevisionQuery())
		    -> setViewer($user)
        -> needRelationships(true)
        -> needCommitPHIDs(true)
        -> needDiffIDs(true)
        -> needActiveDiffs(true)
        -> needHashes(true)
		    -> withIDs($request->getValue('dependsOnDiffs')['remove']);

        $oldrevisions = $dquery->execute();
        $oldrevisions = array_map(function($obj){ return $obj->getPHID();}, $oldrevisions);
    }
    else {
      $oldrevisions = array();
    }



    $txn_editor = $revision->getApplicationTransactionEditor()
      ->setActor($user)
      ->setContentSource($request->newContentSource())
      ->setContinueOnMissingFields(true)
      ->setContinueOnNoEffect(true);
    // Tasks
    $txn_template = $revision->getApplicationTransactionTemplate()
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $edge_type)
      ->setNewValue([
          '+' => array_fuse($add_phids),
          '-' => array_fuse($rem_phids),
        ]);

    try {
      $tactions = $txn_editor->applyTransactions(
        $revision->getApplicationTransactionObject(),
        [$txn_template]);
    } catch (PhabricatorEdgeCycleException $ex) {
//      throw new ConduitException('ERR_GRAPH_CYCLE');
      // Don't throw an exception in this case as phab does a better job of filling in reverse deps than trac, just ignore it
      $tactions = array();
    }

    // Tasks
    $txn_template = $revision->getApplicationTransactionTemplate()
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $redge_type)
      ->setNewValue([
          '+' => array_fuse($badd_phids),
          '-' => array_fuse($brem_phids),
        ]);

    try {
      $tactions = $txn_editor->applyTransactions(
        $revision->getApplicationTransactionObject(),
        [$txn_template]);
    } catch (PhabricatorEdgeCycleException $ex) {
//      throw new ConduitException('ERR_GRAPH_CYCLE');
      // Don't throw an exception in this case as phab does a better job of filling in reverse deps than trac, just ignore it
      $tactions = array();
    }

    // Commits
    $ctxn_template = $revision->getApplicationTransactionTemplate()
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $cedge_type)
      ->setNewValue([
          '+' => array_fuse(array_values($commits)),
        ]);

    try {
      $cactions = $txn_editor->applyTransactions(
        $revision->getApplicationTransactionObject(),
        [$ctxn_template]);
    } catch (PhabricatorEdgeCycleException $ex) {
      throw new ConduitException('ERR_GRAPH_CYCLE');
    }
    // Diffs

    $dtxn_template = $revision->getApplicationTransactionTemplate()
      ->setTransactionType(PhabricatorTransactions::TYPE_EDGE)
      ->setMetadataValue('edge:type', $dedge_type)
      ->setNewValue([
          '+' => array_fuse(array_values($newrevisions)),
          '-' => array_fuse(array_values($oldrevisions)),
        ]);

    try {
      $dactions = $txn_editor->applyTransactions(
        $revision->getApplicationTransactionObject(),
        [$dtxn_template]);
    } catch (PhabricatorEdgeCycleException $ex) {
      throw new ConduitException('ERR_GRAPH_CYCLE');
    }

    $mactions = array_merge( $dactions, $cactions, $tactions );
    $xactions = array_map(function($obj){ return $obj->getPHID();}, $mactions);

    return [ 'transactions' => $xactions
           , 'otherret' => $request->getValue("dependsOnDiffs") ];
  }

}
