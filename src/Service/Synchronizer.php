<?php

namespace Drupal\simplenews_automatically_subscribe\Service;
use Drupal\user\Entity\Role;

class Synchronizer {

    protected $unsubscribe_limit = NULL;
    protected $subscribe_limit = NULL;
    protected $subscription_manager = NULL;
    
    function __construct() {
        $this->unsubscribe_limit = \Drupal::state()->get('simplenews_roles_unsubscribe_limit', 1000);
        $this->subscribe_limit = \Drupal::state()->get('simplenews_roles_subscribe_limit', 1000);
        $this->subscription_manager = \Drupal::service('simplenews.subscription_manager');
    }

    //get list of all roles.
    function getRoles() {
        $roles = [];
        $user_roles = Role::loadMultiple();
        foreach ($user_roles as $key => $role) {
            $roles[$key] = $role->id();
        }
        return $roles;
    }

    function getUsers() {
        $userStorage = \Drupal::entityTypeManager()->getStorage('user');
        $uids = \Drupal::entityQuery('user')
                ->condition('status', '1')
                ->range(0, 10)
                ->execute();
        $users = $userStorage->loadMultiple($uids);
        return $users;
    }

    function getRolesCondition($query, $roles) {
        if(empty($query) || empty($roles)) {
            return FALSE;
        }
        $conditions = $query->orConditionGroup();
        foreach ($roles as $rid) {
            if(empty($rid)) {
                continue;
            }
          $conditions->condition('ur.roles_target_id', $rid);
        }
        return $conditions;
    }


    //get list of user id has roles in list $roles.
    function getUidUsers($roles) {
        $uids = [];
        $query = \Drupal::database()->select('users', 'u');
        $query->fields('u', array('uid'));
        $query->leftJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
        $query->condition($this->getRolesCondition($query, $roles));
        $result = $query->execute()->fetchAll();
        if(count($result) > 0) {
            foreach($result as $item) {
                $uids[] = $item->uid;
            }
        }
        return $uids;
    }

    //get list of user id subscribe on newsletter
    function getSubscribeUid($id) {
        $uids = [];
        $query = \Drupal::database()->select('simplenews_subscriber', 'subscriber');
        $query->fields('subscriber', ['uid']);
        $query->leftJoin('simplenews_subscriber__subscriptions', 'subscription', 'subscriber.id = subscription.entity_id');
        $query->condition('subscription.subscriptions_target_id', $id);
        $result = $query->execute()->fetchAll();
        if(count($result) > 0) {
            foreach ($result as $item) {
              $uids[] = $item->uid;
            }
            return $uids;
        }
        return FALSE;
    }

    // query for subscribe user to newsletter.
    function SubscribeUser($id, $roles) {
        $query =  \Drupal::database()->select('users', 'u');
        $query->leftJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
        $query->leftJoin('users_field_data', 'ufd', 'ufd.uid = u.uid');
        $query->fields('ufd', ['mail']);
        $query->condition('u.uid', 0, '>');
        $query->condition($this->getRolesCondition($query, $roles));
        $query->condition('u.uid', $this->getSubscribeUid($id), 'NOT IN');
        $query->range(0, $this->subscribe_limit);
        $result = $query->execute()->fetchAll();
        if(count($result) > 0) {
            foreach ($result as $item) {
                $this->subscribe($account->mail, $id, FALSE, $source = 'simplenews_automatically_subscribe');
            }
            return TRUE;
        }
        return FALSE;
    }
    
    // query for unsubscribe user from newsletter.
    function UnsubscribeUser($autoRemove, $id, $roles) {
        if(!$autoRemove) {
            return FALSE;
        }
        $query = \Drupal::database()->select('simplenews_subscriber', 'subscriber');
        $query->fields('subscriber', ['mail']);
        $query->leftJoin('simplenews_subscriber__subscriptions', 'subscription', 'subscriber.id = subscription.entity_id');
        $query->condition('subscription.subscriptions_target_id', $id);
        $query->condition('subscriber.uid', $this->getUidUsers($roles), 'NOT IN');
        $query->range(0, $this->unsubscribe_limit);
        $result = $query->execute()->fetchAll();
        if(count($result) > 0) {
            foreach ($result as $account) {
                $this->unsubscribe($account->mail, $id, FALSE, $source = 'simplenews_automatically_subscribe');
            }
            return TRUE;
        }
        return FALSE;
    }

    function subscribe($mail, $id, $confirm, $source ) {
        if(empty($mail) || empty($id)) {
            return FALSE;
        }
        $this->subscription_manager->subscribe($mail, $id, FALSE);
    }

    function unsubscribe($mail, $id, $confirm, $source ) {
        if(empty($mail) || empty($id)) {
            return FALSE;
        }
        $this->subscription_manager->unsubscribe($mail, $id, FALSE);
    }
}
