<?php

namespace Community\EggBrowser\Exceptions;

use Exception;

class EggInstallException extends Exception
{
    public static function alreadyInstalled(string $name): self
    {
        return new self("Egg \"{$name}\" is already installed. Use force/update to overwrite.");
    }

    public static function localChangesRequireForce(string $name): self
    {
        return new self("Egg \"{$name}\" has local changes. Confirm force update to overwrite.");
    }
}
