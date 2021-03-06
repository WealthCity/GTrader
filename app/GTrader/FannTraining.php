<?php

namespace GTrader;

//use GTrader\Strategies\Fann as FannStrategy;
use Illuminate\Database\Eloquent\Model;
use GTrader\Lock;
use GTrader\Exchange;
use GTrader\Series;
use GTrader\Strategy;
use GTrader\Strategies\Fann as FannStrategy;
use GTrader\TrainingManager;

class FannTraining extends Model
{
    use Skeleton, HasStrategy;


    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'fann_training';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'options' => 'array',
    ];


    public function run()
    {
        echo 'FannTraining::run() ID:'.$this->id."\n";

        $training_lock = 'training_'.$this->id;
        if (!Lock::obtain($training_lock)) {
            throw new \Exception('Could not obtain training lock for '.$this->id);
        }

        $exchange_name = Exchange::getNameById($this->exchange_id);
        $symbol_name = Exchange::getSymbolNameById($this->symbol_id);

        $train_suffix = '.train';

        foreach ([
            'train_start', 'train_end',
            'test_start', 'test_end',
            'verify_start', 'verify_end'
        ] as $field) {
            if (!isset($this->options[$field]) || !$this->options[$field]) {
                throw new \Exception('Missing field: '.$field);
            }
        }

        // Set up training strategy
        $train_candles = new Series([
            'exchange' => $exchange_name,
            'symbol' => $symbol_name,
            'resolution' => $this->resolution,
            'start' => $this->options['train_start'],
            'end' => $this->options['train_end'],
            'limit' => 0
        ]);

        define('FANN_WAKEUP_PREFERRED_SUFFX', $train_suffix);
        $train_strategy = Strategy::load($this->strategy_id);
        $train_strategy->setCandles($train_candles);

        // Set up test strategy

        $test_candles = new Series([
            'exchange' => $exchange_name,
            'symbol' => $symbol_name,
            'resolution' => $this->resolution,
            'start' => $this->options['test_start'],
            'end' => $this->options['test_end'],
            'limit' => 0
        ]);
        $test_strategy = clone $train_strategy;
        $test_strategy->setCandles($test_candles);

        // Set up verify strategy

        $verify_candles = new Series([
            'exchange' => $exchange_name,
            'symbol' => $symbol_name,
            'resolution' => $this->resolution,
            'start' => $this->options['verify_start'],
            'end' => $this->options['verify_end'],
            'limit' => 0
        ]);
        $verify_strategy = clone $train_strategy;
        $verify_strategy->setCandles($verify_candles);

        // Set up status object
        $status = new \stdClass();
        $status->exchange = $exchange_name;
        $status->symbol = $symbol_name;
        $status->resolution = $this->resolution;
        $status->train_start = $this->options['train_start'];
        $status->train_end = $this->options['train_end'];
        $status->test_start = $this->options['test_start'];
        $status->test_end = $this->options['test_end'];
        $status->verify_start = $this->options['verify_start'];
        $status->verify_end = $this->options['verify_end'];
        $status->state = 'training';
        //$this->writeStatus($train_strategy, json_encode($status));

        $epochs = 0;            // current epoch count
        $epoch_jump = 1;        // amount of epoch between balance checks
        $no_improvement = 0;    // keep track of the length of the period without improvement
        $max_boredom = 10;      // increase jump size after this many checks without improvement
        $epoch_jump_max = 100;  // max amount of skipped epochs

        if ($json = $this->readStatus($train_strategy)) {
            if ($json = json_decode($json)) {
                if (is_object($json)) {
                    if (isset($json->epochs)) {
                        $epochs = $json->epochs;
                    }
                    if (isset($json->epoch_jump)) {
                        $epoch_jump = $json->epoch_jump;
                    }
                    if (isset($json->test_balance_max)) {
                        $test_balance_max = $json->test_balance_max;
                    }
                    if (isset($json->verify_balance)) {
                        $verify_balance = $json->verify_balance;
                    }
                    if (isset($json->verify_balance_max)) {
                        $verify_balance_max = $json->verify_balance_max;
                    }
                    if (isset($json->no_improvement)) {
                        $no_improvement = $json->no_improvement;
                    }
                }
            }
        }

        if (!isset($test_balance_max)) {
            $test_balance_max = $test_strategy->getLastBalance(true);
        }

        if (!isset($verify_balance)) {
            $verify_balance = 0;
        }
        if (!isset($verify_balance_max)) {
            $verify_balance_max = $verify_strategy->getLastBalance(true);
        }

        while ($this->shouldRun()) {
            $epochs += $epoch_jump;

            // Do the actual training
            $train_strategy->train($epoch_jump);

            // Assign fann to test strat
            $test_strategy->setFann($train_strategy->copyFann());

            // Get test balance
            $test_balance = $test_strategy->getLastBalance(true);
            error_log('Training test_bal: '.$test_balance);

            $no_improvement++;

            if ($test_balance > $test_balance_max) {

                $test_balance_max = $test_balance;

                // Assign fann to verify strat
                $verify_strategy->setFann($train_strategy->copyFann());

                // Get verify balance
                $verify_balance = $verify_strategy->getLastBalance(true);

                if ($verify_balance > $verify_balance_max) {

                    $verify_balance_max = $verify_balance;

                    // There is improvement, save the fann
                    $no_improvement--;
                    $train_strategy->saveFann();
                    $no_improvement = 0;
                    $epoch_jump = 1;
                }
            }

            if ($no_improvement > $max_boredom) {
                // Increase jump size to fly over valleys faster, possibly missing some narrow peaks
                $epoch_jump++;

                //
                if ($epoch_jump >= $epoch_jump_max) {
                    $epoch_jump = $epoch_jump_max;
                }
                $no_improvement = 0;
            }

            // Set and write status file
            $status->epochs = $epochs;
            $status->epoch_jump = $epoch_jump;
            $status->no_improvement = $no_improvement;
            $status->test_balance = number_format(floatval($test_balance), 2, '.', '');
            $status->test_balance_max = number_format(floatval($test_balance_max), 2, '.', '');
            $status->verify_balance = number_format(floatval($verify_balance), 2, '.', '');
            $status->verify_balance_max = number_format(floatval($verify_balance_max), 2, '.', '');
            $status->signals = $test_strategy->getNumSignals(true);
            $this->writeStatus($train_strategy, json_encode($status));
        }

        // Put training back to queue
        $status->state = 'queued';
        $this->writeStatus($train_strategy, json_encode($status));

        // Save current trained fann for the next run
        $train_strategy->saveFann($train_suffix);

        Lock::release($training_lock);
    }



    protected function shouldRun()
    {
        static $started;

        if (!$started) {
            $started = time();
        }

        // check db if we have been stopped or deleted
        try {
            self::where('id', $this->id)
                ->where('status', 'training')
                ->firstOrFail();
        } catch (\Exception $e) {
            echo "Training stopped.\n";
            return false;
        }
        // check if the number of active trainings is greater than the number of slots
        if (self::where('status', 'training')->count() > TrainingManager::getSlotCount()) {
            // check if we have spent too much time
            if ((time() - $started) > $this->getParam('max_time_per_session')) {
                echo 'Time up: '.(time() - $started).'/'.$this->getParam('max_time_per_session')."\n";
                return false;
            }
        }
        return true;
    }


    protected function writeStatus(FannStrategy $strategy, string $s)
    {
        $file = $strategy->path().'.status';
        if (!($fp = fopen($file, 'wb'))) {
            return false;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        $n = fwrite($fp, $s);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $n;
    }


    public function readStatus(FannStrategy $strategy)
    {
        $statusfile = $strategy->path().'.status';
        if (is_file($statusfile)) {
            if (is_readable($statusfile)) {
                if ($fp = fopen($statusfile, 'rb')) {
                    if (flock($fp, LOCK_SH)) {
                        $c = file_get_contents($statusfile);
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        return $c;
                    }
                }
            }
        }
        return '{}';
    }

    public function resetStatus(FannStrategy $strategy)
    {
        $statusfile = $strategy->path().'.status';
        if (is_file($statusfile)) {
            if (is_writable($statusfile)) {
                unlink($statusfile);
            }
        }
    }
}
