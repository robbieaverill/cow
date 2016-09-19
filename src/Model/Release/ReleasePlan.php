<?php


namespace SilverStripe\Cow\Model\Release;

use InvalidArgumentException;

/**
 * Represents a complete release, which will contain a list of many {@see ModuleReales} instances
 */
class ReleasePlan
{
    /**
     * @var LibraryRelease
     */
    protected $rootRelease = null;

    /**
     * Map of module names to releases
     *
     * @var LibraryRelease[]
     */
    protected $items = [];

    /**
     * Sets root object
     *
     * @param LibraryRelease $release
     */
    public function addRootItem(LibraryRelease $release) {
        if ($this->rootRelease) {
            throw new InvalidArgumentException("Cannot add more than one root recipe to a release");
        }
        $this->rootRelease = $release;
        $this->addItem($release);
    }

    /**
     * @param LibraryRelease $parent
     * @param LibraryRelease $release
     */
    public function addChildItem(LibraryRelease $parent, LibraryRelease $release) {
        $this->addItem($release);
        $parent->addChildItem($release);
    }

    /**
     * Add this item to the release
     *
     * @param LibraryRelease $release
     */
    protected function addItem(LibraryRelease $release) {
        $name = $release->getLibrary()->getName();
        if (isset($this->items[$name])) {
            throw new InvalidArgumentException("Module $name cannot be added to this release twice");
        }

        $this->items[$name] = $release;
    }

    /**
     * Get the root item for this plan
     *
     * @return LibraryRelease
     */
    public function getRootItem() {
        return $this->rootRelease;
    }

    /**
     * Find release by library name
     *
     * @param string $name
     * @return LibraryRelease
     */
    public function getItemByLibrary($name) {
        if (isset($this->items[$name])) {
            return $this->items[$name];
        }
        throw new InvalidArgumentException("No version for module {$name} exists.");
    }
}
