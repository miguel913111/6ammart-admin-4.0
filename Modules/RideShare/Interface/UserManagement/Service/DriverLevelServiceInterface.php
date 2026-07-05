<?php

namespace Modules\RideShare\Interface\UserManagement\Service;

interface DriverLevelServiceInterface
{
    /**
     * Find a single driver level by the given criteria.
     *
     * @param array $criteria
     * @return object|null
     */
    public function findOneBy(array $criteria): ?object;
}
