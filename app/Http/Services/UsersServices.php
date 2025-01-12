<?php

namespace App\Http\Services;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
     * Get User resource.
     * @param User $user
     * @return UserResource
     */
    public function getUser(User $user): UserResource
    {
        return UserResource::make($user);
    }

    /**
     * Get All users.
     * @return AnonymousResourceCollection
     */
    public function getUsersWithPagination(): AnonymousResourceCollection
    {
        $search = $this->request->input('search') ?? '';
        $perPage = $this->request->input('itemsPerPage') ?? 25;

        $userBuild = User::query();

        if ($search) {
            $userBuild = $userBuild->where(function($query) use ($search){
                $query->where('name', 'LIKE', "%$search%")
                    ->orWhere('email', 'LIKE', "%$search%");
            });
        }

        return UserResource::collection($userBuild->paginate($perPage));
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
