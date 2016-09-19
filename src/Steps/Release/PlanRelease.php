<?php


namespace SilverStripe\Cow\Steps\Release;

use Exception;
use SilverStripe\Cow\Commands\Command;
use SilverStripe\Cow\Model\Modules\Library;
use SilverStripe\Cow\Model\Release\LibraryRelease;
use SilverStripe\Cow\Model\Modules\Project;
use SilverStripe\Cow\Model\Release\ReleasePlan;
use SilverStripe\Cow\Model\Release\Version;
use SilverStripe\Cow\Steps\Step;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class PlanRelease extends Step
{
    /**
     * @var Project
     */
    protected $project;

    /**
     * @var Version
     */
    protected $version;

    public function __construct(Command $command, Project $project, Version $version)
    {
        parent::__construct($command);
        $this->setProject($project);
        $this->setVersion($version);
    }

    public function getStepName()
    {
        return 'plan';
    }

    public function run(InputInterface $input, OutputInterface $output)
    {
        $name = $this->getProject()->getName();
        $version = $this->getVersion()->getValue();
        $this->log($output, "Planning release for project {$name} version {$version}");

        // Build initial plan
        $plan = $this->generatePlan($output);

        // Review with author
        $plan = $this->reviewPlan($output, $input, $plan);
    }

    /**
     * Generate a draft plan for the current project based on configuration and automatic best-guess
     * @param OutputInterface $output
     * @return ReleasePlan
     */
    protected function generatePlan(OutputInterface $output) {
        $plan = new ReleasePlan();

        // Add the provided version as the root plan item
        $moduleRelease = new LibraryRelease($this->getProject(), $this->getVersion());
        $plan->addRootItem($moduleRelease);

        // Recursively build child releases
        $this->generateChildReleases($plan, $moduleRelease);
        return $plan;
    }

    /**
     * Recursively generate a plan for this parent recipe
     *
     * @param ReleasePlan $plan In-progress plan
     * @param LibraryRelease $parent Parent release object
     * @throws Exception
     */
    protected function generateChildReleases(ReleasePlan $plan, LibraryRelease $parent) {
        // Get children
        $childModules = $parent->getLibrary()->getChildren();
        foreach($childModules as $childModule) {
            // For the given child module, guess the upgrade mechanism (upgrade or new tag)
            if ($parent->getLibrary()->isChildUpgradeOnly($childModule->getName())) {
                $release = $this->generateUpgradeRelease($parent, $childModule);
            } else {
                $release = $this->proposeNewReleaseVersion($parent, $childModule);
            }
            $plan->addChildItem($parent, $release);

            // If this release tag doesn't match an existing tag, then recurse.
            // If the tag exists, then we are simply updating the dependency to
            // an existing tag, so there's no need to recursie.
            $tags = $childModule->getTags();
            if (!array_key_exists($release->getVersion()->getValue(), $tags)) {
                $this->generateChildReleases($plan, $release);
            }
        }
    }

    /**
     * Determine the best existing stable tag to upgrade a dependency to
     *
     * @param LibraryRelease $parentRelease
     * @param Library $childModule
     * @return LibraryRelease
     * @throws Exception
     */
    protected function generateUpgradeRelease(LibraryRelease $parentRelease, Library $childModule) {
        // Get tags and composer constraint to filter by
        $tags = $childModule->getTags();
        $constraint = $parentRelease->getLibrary()->getChildConstraint(
            $childModule->getName(),
            $parentRelease->getVersion()
        );

        // Upgrade to self.version
        if ($constraint->isSelfVersion()) {
            $candidateVersion = $parentRelease->getVersion();
            if (!array_key_exists($candidateVersion->getValue(), $tags)) {
                throw new Exception(
                    "Library " . $childModule->getName() . " cannot be upgraded to version "
                    . $candidateVersion->getValue() . " without a new release"
                );
            }
            return new LibraryRelease($childModule, $candidateVersion);
        }

        // Get all stable tags that match the given composer constraint
        $candidates = $constraint->filterVersions($tags);
        foreach($candidates as $tag => $version) {
            if (!$version->isStable()) {
                unset($candidates[$tag]);
            }
        }

        // Check if we have any candidates left
        if (empty($candidates)) {
            throw new Exception(
                "Library " . $childModule->getName() . " has no available stable tags that matches "
                . $constraint->getValue()
                . ". Please remove upgrade-only for this module, or tag a new release."
            );
        }

        // Upgrade to highest version
        $tags = Version::sort($candidates, 'descending');
        $candidateVersion = reset($tags);
        return new LibraryRelease($childModule, $candidateVersion);
    }

    /**
     * Propose a new version to tag for a given dependency
     *
     * @param LibraryRelease $parentRelease
     * @param Library $childModule
     * @return mixed|Version
     * @throws Exception
     */
    protected function proposeNewReleaseVersion(LibraryRelease $parentRelease, Library $childModule) {
        // Get tags and composer constraint to filter by
        $tags = $childModule->getTags();
        $constraint = $parentRelease->getLibrary()->getChildConstraint(
            $childModule->getName(),
            $parentRelease->getVersion()
        );

        // Upgrade to self.version
        if ($constraint->isSelfVersion()) {
            $candidateVersion = $parentRelease->getVersion();

            // If this is already tagged, just upgrade without a new release
            if (array_key_exists($candidateVersion->getValue(), $tags)) {
                return new LibraryRelease($childModule, $candidateVersion);
            }

            // Build release
            return new LibraryRelease($childModule, $candidateVersion);
        }

        // Get stability to use for the new tag
        $useSameStability = $parentRelease->getLibrary()->isStabilityInherited();
        if($useSameStability) {
            $stability = $parentRelease->getVersion()->getStability();
            $stabilityVersion = $parentRelease->getVersion()->getStabilityVersion();
        } else {
            $stability = '';
            $stabilityVersion = null;
        }

        // Filter versions
        $candidates = $constraint->filterVersions($tags);
        $tags = Version::sort($candidates, 'descending');

        // Determine which best tag to create (with the correct stability)
        $existingTag = reset($tags);
        if($existingTag) {
            // Increment from to guess next version
            $version = $existingTag->getNextVersion($stability, $stabilityVersion);
        } else {
            // In this case, the lower bounds of the constraint isn't a valid tag,
            // so this is our new candidate
            $version = clone $constraint->getMinVersion();
            $version->setStability($stability);
            $version->setStabilityVersion($stabilityVersion);
        }

        // Report new tag
        return new LibraryRelease($childModule, $version);
    }

    /**
     * @return Project
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * @param Project $project
     * @return $this
     */
    public function setProject($project)
    {
        $this->project = $project;
        return $this;
    }

    /**
     * @param Version $version
     * @return $this
     */
    public function setVersion($version)
    {
        $this->version = $version;
        return $this;
    }

    /**
     * @return Version
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * Interactively confirm a plan with the user
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param ReleasePlan $plan
     */
    protected function reviewPlan(OutputInterface $output, InputInterface $input, ReleasePlan $plan)
    {
        $releaseLines = $this->getReleaseOptions($plan->getRootItem());

        // If not interactive, simply output read-only list of versions
        $message = "The below release plan has been generated for this project";
        if (!$input->isInteractive()) {
            $this->log($output, $message);
            foreach($releaseLines as $line) {
                $this->log($output, $line);
            }
            return $plan;
        }

        // Prompt user with query to modify this plan
        $question = new ChoiceQuestion(
            "{$message}; Please confirm any manual changes below:",
            array_merge(
                ["continue" => "continue"],
                $releaseLines
            ),
            "continue"
        );
        $selectedLibrary = $this->getQuestionHelper()->ask($input, $output, $question);
        if($selectedLibrary === 'continue') {
            return $plan;
        }

        // Modify selected dependency
        $selectedRelease = $plan->getItemByLibrary($selectedLibrary);
        $this->reviewLibraryVersion($output, $input, $selectedRelease);

        // Recursively update plan
        return $this->reviewPlan($output, $input, $plan);
    }

    /**
     * Update the version of a selected library
     *
     * @param OutputInterface $output
     * @param InputInterface $input
     * @param LibraryRelease $selectedVersion
     * @internal param ReleasePlan $plan
     */
    protected function reviewLibraryVersion(
        OutputInterface $output,
        InputInterface $input,
        LibraryRelease $selectedVersion
    ) {
        $question = new Question(
            "Please enter a new version to release for <info>" . $selectedVersion->getLibrary()->getName() . "</info>: ",
            $selectedVersion->getVersion()
        );
        $newVersionName = $this->getQuestionHelper()->ask($input, $output, $question);

        // If version is valid, update and return
        if (Version::parse($newVersionName)) {
            $newVersion = new Version($newVersionName);
            $selectedVersion->setVersion($newVersion);
            return;
        }

        // If error, repeat
        $this->log(
            $output,
            "Invalid version {$newVersionName}; Please enter a tag in w.x.y(-[rc|alpha|beta][z]) format",
            "error"
        );
        $this->reviewLibraryVersion($output, $input, $selectedVersion);
    }

    /**
     * Build user-visible option selection list based on a prepared plan
     *
     * @param LibraryRelease $node
     * @param int $depth
     * @return array List of options
     */
    protected function getReleaseOptions(LibraryRelease $node, $depth = 0) {
        $options = [];
        // Format / indent this line
        $formatting = str_repeat(' ', $depth) . ($depth ? html_entity_decode('&#x2514;', ENT_NOQUOTES, 'UTF-8') . ' ' : '');

        // Get version release information
        if ($node->getIsNewRelease()) {
            $version = ' (<info>' . $node->getVersion()->getValue() . '</info>) new tag';

            // If releasing a new tag, show previous version
            $tags = $node->getLibrary()->getTags();
            $previous = $node->getVersion()->getPriorVersionFromTags($tags, $node->getLibrary()->getName());
            if ($previous) {
                $version .= ', prior version <comment>' . $previous->getValue() . '</comment>';
            }
        } else {
            $version = ' (<comment>' . $node->getVersion()->getValue() . '</comment> existing tag)';
        }

        // Build string
        $options[$node->getLibrary()->getName()] = $formatting . $node->getLibrary()->getName() . $version;

        // Build child version options
        foreach($node->getChildren() as $child) {
            $options = array_merge(
                $options,
                $this->getReleaseOptions($child, $depth ? $depth + 3 : 1)
            );
        }
        return $options;
    }
}