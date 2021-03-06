<?php

/*
 * This file is part of the Orient package.
 *
 * (c) Alessandro Nadalin <alessandro.nadalin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Class Address
 *
 * @package
 * @subpackage
 * @author      Alessandro Nadalin <alessandro.nadalin@gmail.com>
 * @author      David Funaro <ing.davidino@gmail.com>
 */

namespace Doctrine\ODM\OrientDB\Tests\Models\Standard;

/**
 * @Document(oclass="Country")
 */
class Country
{
    /**
     * @RID
     */
    protected $rid;

    /**
     * @Version
     */
    public $version;

    /**
     * @Property(name="name", type="string", nullable=true)
     */
    public $name;

    /**
     * @return mixed
     */
    public function getRid() {
        return $this->rid;
    }
}
