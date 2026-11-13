<?php

namespace App\Http\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
            'password' => Hash::make($this->request->input('password')),
        ]);
    }

    /**
     * Update user info;
     * @param User $user
     * @return User|Authenticatable
     */
    public function update(User $user): User|Authenticatable
    {
        $this->user = $user;

        $this->user->name = $this->request->name;
        $this->user->email = $this->request->email;

        if ($this->request->has('password')) {
            $this->user->password = Hash::make($this->request->input('password'));
        }

        $this->user->save();

        return $this->user;
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
