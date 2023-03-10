<?php

namespace App\Command;

use App\Service\Mastodon;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'mastodon:download',
    description: 'Download all medias from a Mastodon user',
)]
class MastodonDownloadCommand extends Command
{
    public function __construct(private readonly Mastodon $mastodon, private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Download directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $io->ask('Which user should I download ?');

        if (empty($username)) {
            $io->note('No username chose.  Exiting');

            return Command::SUCCESS;
        }

        try {
            $users = $this->mastodon->findUser($username);
        } catch (\Throwable $exception) {
            $io->note('I\'m sorry but this error occurred:');
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $selectedUser = null;

        foreach ($users as $user) {
            if ($io->confirm(sprintf('I\'ve found %s. Is it correct ?', $user['acct']))) {
                $selectedUser = $user;
                break;
            }
        }

        if ($selectedUser === null) {
            $io->warning('Sorry but I couldn\'t find any user matching your request');

            return Command::FAILURE;
        }

        $this->mastodon->setServerForUser($selectedUser);
        // UserId may be different between two servers, even if it's the same user
        // In order to avoid a 404, we need to refresh userId on correct server
        $selectedUser = $this->mastodon->getUserForSelectedServer($selectedUser);

        $directoryQuestion = new Question('In which directory do you want to store the medias ?');
        $directoryQuestion->setAutocompleterCallback(function (string $userInput): array {
            $inputPath = preg_replace('%(/|^)[^/]*$%', '$1', $userInput);
            $inputPath = '' === $inputPath ? '.' : $inputPath;

            $foundFilesAndDirs = @scandir($inputPath) ?: [];

            return array_map(function ($dirOrFile) use ($inputPath) {
                return $inputPath.$dirOrFile;
            }, $foundFilesAndDirs);
        });

        if (!$input->getOption('directory') || !is_writable($input->getOption('directory'))) {
            $directory = $io->askQuestion($directoryQuestion);

            if (!is_writable($directory)) {
                $io->error(sprintf('Sorry, directory %s is not writable', $directory));

                return Command::FAILURE;
            }
        } else {
            $directory = $input->getOption('directory');
        }

        $targetDirectory = $directory . '/' . $selectedUser['username'];
        if(!is_dir($targetDirectory)) {
            mkdir($targetDirectory);
        }

        $medias = $this->mastodon->getMedias($selectedUser['id'], $io);

        $io->info(sprintf('%d medias have been found.  Starting download', count($medias)));

        $io->progressStart(count($medias));
        foreach ($medias as $link) {
            try {
                if (!is_file($targetDirectory . '/' . basename($link))) {
                    file_put_contents($targetDirectory . '/' . basename($link), $this->httpClient->request(
                        'GET',
                        $link
                    )->getContent());
                }
            } catch (\Throwable $exception) {
                $io->writeln('');
                $io->writeln($exception->getMessage());
            } finally {
                $io->progressAdvance();
            }
        }
        $io->progressFinish();

        $io->success(sprintf('All medias for %s have been downloaded.  Enjoy', $selectedUser['username']));

        return Command::SUCCESS;
    }
}
