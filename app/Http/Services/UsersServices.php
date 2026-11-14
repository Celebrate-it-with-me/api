<?php

namespace App\Http\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class UsersServices
{
    protected Request $request;
    protected Authenticatable $user;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->user = new User();
    }

    /**
     * Create user function.
     * @return Model|Builder
     */
    public function create(): Model|Builder
    {
        return User::query()->create([
            'name' => $this->request->input('name'),
            'email' => $this->request->input('email'),
            'password' => $this->request->input('password'), // hashed by the model cast
        ]);
    }

    /**
     * Delete user from db.
     * @param User $user
     * @return User
     */
    public function destroy(User $user): User
    {
        $userSaved = clone $user;

        $user->delete();

        return $userSaved;
    }
}
