<?php

/**
 * @task lease      Lease Acquisition
 * @task resource   Resource Allocation
 * @task log        Logging
 */
abstract class DrydockBlueprintImplementation extends Phobject {

  private $activeResource;
  private $activeLease;
  private $instance;

  abstract public function getType();
  abstract public function getInterface(
    DrydockResource $resource,
    DrydockLease $lease,
    $type);

  abstract public function isEnabled();

  abstract public function getBlueprintName();
  abstract public function getDescription();

  public function getBlueprintClass() {
    return get_class($this);
  }

  protected function loadLease($lease_id) {
    // TODO: Get rid of this?
    $query = id(new DrydockLeaseQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($lease_id))
      ->execute();

    $lease = idx($query, $lease_id);

    if (!$lease) {
      throw new Exception(pht("No such lease '%d'!", $lease_id));
    }

    return $lease;
  }

  protected function getInstance() {
    if (!$this->instance) {
      throw new Exception(
        pht('Attach the blueprint instance to the implementation.'));
    }

    return $this->instance;
  }

  public function attachInstance(DrydockBlueprint $instance) {
    $this->instance = $instance;
    return $this;
  }

  public function getFieldSpecifications() {
    return array();
  }


/* -(  Lease Acquisition  )-------------------------------------------------- */


  /**
   * Enforce basic checks on lease/resource compatibility. Allows resources to
   * reject leases if they are incompatible, even if the resource types match.
   *
   * For example, if a resource represents a 32-bit host, this method might
   * reject leases that need a 64-bit host. The blueprint might also reject
   * a resource if the lease needs 8GB of RAM and the resource only has 6GB
   * free.
   *
   * This method should not acquire locks or expect anything to be locked. This
   * is a coarse compatibility check between a lease and a resource.
   *
   * @param DrydockBlueprint Concrete blueprint to allocate for.
   * @param DrydockResource Candidiate resource to allocate the lease on.
   * @param DrydockLease Pending lease that wants to allocate here.
   * @return bool True if the resource and lease are compatible.
   * @task lease
   */
  abstract public function canAcquireLeaseOnResource(
    DrydockBlueprint $blueprint,
    DrydockResource $resource,
    DrydockLease $lease);


  /**
   * Acquire a lease. Allows resources to peform setup as leases are brought
   * online.
   *
   * If acquisition fails, throw an exception.
   *
   * @param DrydockBlueprint Blueprint which built the resource.
   * @param DrydockResource Resource to acquire a lease on.
   * @param DrydockLease Requested lease.
   * @return void
   * @task lease
   */
  abstract public function acquireLease(
    DrydockBlueprint $blueprint,
    DrydockResource $resource,
    DrydockLease $lease);

  final public function releaseLease(
    DrydockBlueprint $blueprint,
    DrydockResource $resource,
    DrydockLease $lease) {

    // TODO: This is all broken nonsense.

    $scope = $this->pushActiveScope(null, $lease);

    $released = false;

    $lease->openTransaction();
      $lease->beginReadLocking();
        $lease->reload();

        if ($lease->getStatus() == DrydockLeaseStatus::STATUS_ACTIVE) {
          $lease->release();
          $lease->setStatus(DrydockLeaseStatus::STATUS_RELEASED);
          $lease->save();
          $released = true;
        }

      $lease->endReadLocking();
    $lease->saveTransaction();

    if (!$released) {
      throw new Exception(pht('Unable to release lease: lease not active!'));
    }

  }



/* -(  Resource Allocation  )------------------------------------------------ */


  /**
   * Enforce fundamental implementation/lease checks. Allows implementations to
   * reject a lease which no concrete blueprint can ever satisfy.
   *
   * For example, if a lease only builds ARM hosts and the lease needs a
   * PowerPC host, it may be rejected here.
   *
   * This is the earliest rejection phase, and followed by
   * @{method:canEverAllocateResourceForLease}.
   *
   * This method should not actually check if a resource can be allocated
   * right now, or even if a blueprint which can allocate a suitable resource
   * really exists, only if some blueprint may conceivably exist which could
   * plausibly be able to build a suitable resource.
   *
   * @param DrydockLease Requested lease.
   * @return bool True if some concrete blueprint of this implementation's
   *   type might ever be able to build a resource for the lease.
   * @task resource
   */
  abstract public function canAnyBlueprintEverAllocateResourceForLease(
    DrydockLease $lease);


  /**
   * Enforce basic blueprint/lease checks. Allows blueprints to reject a lease
   * which they can not build a resource for.
   *
   * This is the second rejection phase. It follows
   * @{method:canAnyBlueprintEverAllocateResourceForLease} and is followed by
   * @{method:canAllocateResourceForLease}.
   *
   * This method should not check if a resource can be built right now, only
   * if the blueprint as configured may, at some time, be able to build a
   * suitable resource.
   *
   * @param DrydockBlueprint Blueprint which may be asked to allocate a
   *   resource.
   * @param DrydockLease Requested lease.
   * @return bool True if this blueprint can eventually build a suitable
   *   resource for the lease, as currently configured.
   * @task resource
   */
  abstract public function canEverAllocateResourceForLease(
    DrydockBlueprint $blueprint,
    DrydockLease $lease);


  /**
   * Enforce basic availability limits. Allows blueprints to reject resource
   * allocation if they are currently overallocated.
   *
   * This method should perform basic capacity/limit checks. For example, if
   * it has a limit of 6 resources and currently has 6 resources allocated,
   * it might reject new leases.
   *
   * This method should not acquire locks or expect locks to be acquired. This
   * is a coarse check to determine if the operation is likely to succeed
   * right now without needing to acquire locks.
   *
   * It is expected that this method will sometimes return `true` (indicating
   * that a resource can be allocated) but find that another allocator has
   * eaten up free capacity by the time it actually tries to build a resource.
   * This is normal and the allocator will recover from it.
   *
   * @param DrydockBlueprint The blueprint which may be asked to allocate a
   *   resource.
   * @param DrydockLease Requested lease.
   * @return bool True if this blueprint appears likely to be able to allocate
   *   a suitable resource.
   * @task resource
   */
  abstract public function canAllocateResourceForLease(
    DrydockBlueprint $blueprint,
    DrydockLease $lease);


  /**
   * Allocate a suitable resource for a lease.
   *
   * This method MUST acquire, hold, and manage locks to prevent multiple
   * allocations from racing. World state is not locked before this method is
   * called. Blueprints are entirely responsible for any lock handling they
   * need to perform.
   *
   * @param DrydockBlueprint The blueprint which should allocate a resource.
   * @param DrydockLease Requested lease.
   * @return DrydockResource Allocated resource.
   * @task resource
   */
  abstract public function allocateResource(
    DrydockBlueprint $blueprint,
    DrydockLease $lease);


/* -(  Logging  )------------------------------------------------------------ */


  /**
   * @task log
   */
  protected function logException(Exception $ex) {
    $this->log($ex->getMessage());
  }


  /**
   * @task log
   */
  protected function log($message) {
    self::writeLog(
      $this->activeResource,
      $this->activeLease,
      $message);
  }


  /**
   * @task log
   */
  public static function writeLog(
    DrydockResource $resource = null,
    DrydockLease $lease = null,
    $message = null) {

    $log = id(new DrydockLog())
      ->setEpoch(time())
      ->setMessage($message);

    if ($resource) {
      $log->setResourceID($resource->getID());
    }

    if ($lease) {
      $log->setLeaseID($lease->getID());
    }

    $log->save();
  }


  public static function getAllBlueprintImplementations() {
    return id(new PhutilClassMapQuery())
      ->setAncestorClass(__CLASS__)
      ->execute();
  }

  public static function getNamedImplementation($class) {
    return idx(self::getAllBlueprintImplementations(), $class);
  }

  protected function newResourceTemplate(
    DrydockBlueprint $blueprint,
    $name) {

    $resource = id(new DrydockResource())
      ->setBlueprintPHID($blueprint->getPHID())
      ->attachBlueprint($blueprint)
      ->setType($this->getType())
      ->setStatus(DrydockResourceStatus::STATUS_PENDING)
      ->setName($name);

    $this->activeResource = $resource;

    $this->log(
      pht(
        "Blueprint '%s': Created New Template",
        $this->getBlueprintClass()));

    return $resource;
  }

  private function pushActiveScope(
    DrydockResource $resource = null,
    DrydockLease $lease = null) {

    if (($this->activeResource !== null) ||
        ($this->activeLease !== null)) {
      throw new Exception(pht('There is already an active resource or lease!'));
    }

    $this->activeResource = $resource;
    $this->activeLease = $lease;

    return new DrydockBlueprintScopeGuard($this);
  }

  public function popActiveScope() {
    $this->activeResource = null;
    $this->activeLease = null;
  }

}
