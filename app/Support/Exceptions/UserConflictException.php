<?php

namespace App\Support\Exceptions;

use App\Models\User;
use Exception;

class UserConflictException extends Exception
{
    protected User $user;

    public function __construct(User $user, $message = "A user account with this email already exists.", $code = 409)
    {
        parent::__construct($message, $code);
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
