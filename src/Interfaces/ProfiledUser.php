<?php

namespace Perfbase\Laravel\Interfaces;

interface ProfiledUser
{
    public function shouldBeProfiled(): bool;
}