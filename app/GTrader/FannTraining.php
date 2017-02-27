<?php

namespace GTrader;

//use GTrader\Strategies\Fann as FannStrategy;
use Illuminate\Database\Eloquent\Model;
use GTrader\Lock;
use GTrader\Exchange;
use GTrader\Series;
use GTrader\Strategy;
use GTrader\TrainingManager;


class FannTraining extends Model
{
    use HasParams, HasStrategy;


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


    public function __construct()
    {
        // load params from config file
        $this->setParams(\Config::get(str_replace('\\', '.', get_class($this))));
    }

    public function run()
    {
        echo 'FannTraining::run() ID:'.$this->id."\n";

        $training_lock = 'training_'.$this->id;
        if (!Lock::obtain($training_lock))
            throw new \Exception('Could not obtain training lock for '.$this->id);

        $exchange_name = Exchange::getNameById($this->exchange_id);
        $symbol_name = Exchange::getSymbolNameById($this->symbol_id);

        $status = new \stdClass();
        $status->exchange = $exchange_name;
        $status->symbol = $symbol_name;
        $status->resolution = $this->resolution;
        $status->range_start = $this->range_start;
        $status->range_end = $this->range_end;



        $candles = new Series([
                    'exchange' => $exchange_name,
                    'symbol' => $symbol_name,
                    'resolution' => $this->resolution,
                    'start' => $this->range_start,
                    'end' => $this->range_end,
                    'limit' => 0]);

        //echo 'Candles: '.$candles->size()."\n";

        $strategy = Strategy::load($this->strategy_id);
        $strategy->setCandles($candles);

        $statusfile = $strategy->path().'.status';

        $epochs = 0;
        $epoch_jump = 1;

        if ($json = $this->readStatus())
            if ($json = json_decode($json))
                if (is_object($json))
                {
                    if (isset($json->epochs))
                        $epochs = $json->epochs;
                    if (isset($json->epoch_jump))
                        $epoch_jump = $json->epoch_jump;
                }

        $balance_max = $strategy->getLastBalance(true);
        $no_improvement = 0;
        $max_boredom = 10;

        while ($this->shouldRun())
        {
            $epochs += $epoch_jump;
            $strategy->train($epoch_jump);
            $balance = $strategy->getLastBalance(true);
            if ($balance > $balance_max)
            {
                $strategy->saveFann();
                $balance_max = $balance;
                $no_improvement = 0;
                $epoch_jump = 1;
            }
            else
            {
                $no_improvement++;
            }
            if ($no_improvement > $max_boredom)
            {
                $epoch_jump++;
                if ($epoch_jump >= 100)
                    $epoch_jump = 100;
                $no_improvement = 0;
            }

            $status->epochs = $epochs;
            $status->epoch_jump = $epoch_jump;
            $status->balance = number_format($balance, 2);
            $status->balance_max = number_format($balance_max, 2);
            $status->signals = $strategy->getNumSignals(true);

            $this->writeStatus($statusfile, json_encode($status));
        }

        Lock::release($training_lock);
    }


    protected function shouldRun()
    {
        static $started;

        if (!$started) $started = time();

        // check db if we have been stopped or deleted
        try
        {
            self::where('id', $this->id)
                ->where('status', 'training')
                ->firstOrFail();
        }
        catch (\Exception $e)
        {
            echo "Training stopped.\n";
            return false;
        }
        // check if the number of active trainings is greater than the number of slots
        if (self::where('status', 'training')->count() > TrainingManager::getSlotCount())
        {
            // check if we have spent too much time
            if ((time() - $started) > $this->getParam('max_time_per_session'))
            {
                echo 'Time up: '.(time() - $started).'/'.$this->getParam('max_time_per_session')."\n";
                return false;
            }
        }
        return true;
    }


    protected function writeStatus(string $file, string $s)
    {
        if (!($fp = fopen($file, 'wb')))
            return false;
        if (!flock($fp, LOCK_EX))
        {
            fclose($fp);
            return false;
        }
        $n = fwrite($fp, $s);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $n;
    }


    public function readStatus()
    {
        $strategy = Strategy::load($this->strategy_id);
        $statusfile = $strategy->path().'.status';
        if (is_file($statusfile))
            if (is_readable($statusfile))
                if ($fp = fopen($statusfile, 'rb'))
                    if (flock($fp, LOCK_SH))
                    {
                        $c = file_get_contents($statusfile);
                        flock($fp, LOCK_UN);
                        fclose($fp);
                        return $c;
                    }
        return '{}';
    }
}






