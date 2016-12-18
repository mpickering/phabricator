<?php

final class ManiphestCreateTaskConduitAPIMethod
  extends ManiphestConduitAPIMethod {

  public function getAPIMethodName() {
    return 'maniphest.createtask';
  }

  public function getMethodDescription() {
    return pht('Create a new Maniphest task.');
  }

  protected function defineParamTypes() {
    return $this->getTaskFields($is_new = true);
  }

  protected function defineReturnType() {
    return 'nonempty dict';
  }

  protected function defineErrorTypes() {
    return array(
      'ERR-INVALID-PARAMETER' => pht('Missing or malformed parameter.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $phids = $request->getValue('user');

    $query = id(new PhabricatorPeopleQuery())
      ->setViewer($request->getUser())
      ->needProfileImage(true)
      ->needAvailability(true)
      ->withPHIDs($phids);
    
    $users = $query->execute();
  
    $authorUser = array_values($users)[0];

    $task = ManiphestTask::initializeNewTask($authorUser);

    $task = $this->applyRequest($task, $request, $authorUser, $is_new = true);

    return $this->buildTaskInfoDictionary($task);
  }

}
