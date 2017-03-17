<?php

namespace ContinuousPipe\Billing\BillingProfile;

use ContinuousPipe\Security\Team\Team;
use ContinuousPipe\Security\User\User;
use Ramsey\Uuid\UuidInterface;

interface UserBillingProfileRepository
{
    /**
     * @param UuidInterface $uuid
     *
     * @throws UserBillingProfileNotFound
     *
     * @return UserBillingProfile
     */
    public function find(UuidInterface $uuid) : UserBillingProfile;

    /**
     * Find the billing profile of the user.
     *
     * @param User $user
     *
     * @throws UserBillingProfileNotFound
     *
     * @return UserBillingProfile
     */
    public function findByUser(User $user) : UserBillingProfile;

    /**
     * Find all the billing profiles of the user.
     *
     * @param User $user
     *
     * @throws UserBillingProfileNotFound
     *
     * @return UserBillingProfile[]
     */
    public function findAllByUser(User $user): array;

    /**
     * Find the billing profile for the given team.
     *
     * @param Team $team
     *
     * @throws UserBillingProfileNotFound
     *
     * @return UserBillingProfile
     */
    public function findByTeam(Team $team) : UserBillingProfile;

    /**
     * @param UserBillingProfile $billingProfile
     */
    public function save(UserBillingProfile $billingProfile);

    /**
     * @param Team $team
     * @param UserBillingProfile $billingProfile
     */
    public function link(Team $team, UserBillingProfile $billingProfile);

    /**
     * @param Team $team
     * @param UserBillingProfile $billingProfile
     */
    public function unlink(Team $team, UserBillingProfile $billingProfile);

    /**
     * @param UuidInterface $billingProfileUuid
     *
     * @return Team[]
     */
    public function findRelations(UuidInterface $billingProfileUuid);
}
