<?php

require_once 'w8io_pairs.php';
require_once 'w8io_nodes.php';

class w8io_blockchain
{
    private $blocks;
    private $checkpoint;
    private $nodes;

    public function __construct( $writable = true )
    {
        $this->blocks = new w8io_pairs( W8IO_DB_BLOCKCHAIN, 'blocks', $writable, 'INTEGER PRIMARY KEY|BLOB|0|0', 256 );
        $this->checkpoint = new w8io_pairs( $this->blocks->get_db(), 'checkpoint', $writable, 'INTEGER PRIMARY KEY|TEXT|0|0' );
        $this->nodes = new w8io_nodes( explode( '|', W8IO_NODES ) );
    }

    public function get_height()
    {
        $height = $this->checkpoint->get_value( W8IO_CHECKPOINT_BLOCKCHAIN );

        $height_backup = json_decode( $height, true );
        if( isset( $height_backup['height'] ) )
            return $height_backup['height'];

        if( !$height )
            return 0;
    
        return $height;
    }

    public function get_block( $at )
    {
        $block = $this->blocks->get_value( $at );
        if( $block === false )
            return false;

        $block = json_decode( gzinflate( $block ), true, 512, JSON_BIGINT_AS_STRING );
        if( $block === false )
            return false;

        return $block;
    }

    private function set_block( $block )
    {
        $height = $block['height'];
        $block = gzdeflate( json_encode( $block ), 9 );
        if( $block === false )
            return false;

        return $this->blocks->set_pair( $height, $block );
    }

    public function update()
    {
        $from = $this->get_height();
        $to = $this->nodes->get_height();
        $shift = 0;

        if( !$to )
        {
            w8io_trace( 'w', 'can not get_height()' );
            return false;
        }

        $to--; // highest stable block
        $height = $to;

        if( $to <= $from ) // no new blocks
            return true;

        if( !$this->blocks->begin() )
            w8io_error( 'unexpected begin() error' );

        if( $from )
        {
            // check new blocks
            $local_block = $this->get_block( $from );
            $nodes_block = $this->nodes->get_block( $from + 1 );
            if( $nodes_block === false )
            {
                w8io_trace( 'w', "can not get_block()" );
                $this->blocks->rollback();
                return false;
            }

            if( $nodes_block['reference'] == $local_block['signature'] ) // no fork
            {
                w8io_trace( 'i', "{$nodes_block['height']} (blockchain)" );

                if( !$this->set_block( $nodes_block ) )
                    w8io_error( 'set_block() failed' );

                $shift = 1;
                $signature = $nodes_block['signature'];
            }
            else // fork (fallback -100)
            for( ;; )
            {
                $nodes_block = $this->nodes->get_block( $from );
                if( $nodes_block === false )
                {
                    w8io_trace( 'w', 'can not get_block()' );
                    $this->blocks->rollback();
                    return false;
                }

                if( serialize( $nodes_block ) == serialize( $local_block ) )
                {
                    $shift = 1;
                    $signature = $nodes_block['signature'];
                    break;
                }

                w8io_trace( 'w', "fork at $from (-100)" );
                $from = max( 0, $from - 100 );

                if( $from == 0 )
                    break;

                $local_block = $this->get_block( $from );
            }
        }

        $to = min( $to, $from + W8IO_MAX_UPDATE_BATCH );

        for( $i = $from + $shift + 1; $i <= $to; $i++ )
        {
            if( false === ( $nodes_block = $this->nodes->get_block( $i ) ) )
            {
                w8io_trace( 'w', 'can not get_block()' );
                $this->blocks->rollback();
                return false;
            }

            if( isset( $signature ) && $nodes_block['reference'] != $signature )
            {
                w8io_trace( 'w', "fork at $i" );
                break;
            }

            w8io_trace( 'i', "$i (blockchain)" );          

            if( !$this->set_block( $nodes_block ) )
                w8io_error( 'set_block() failed' );

            $signature = $nodes_block['signature'];
        }

        if( false === $this->checkpoint->set_pair( W8IO_CHECKPOINT_BLOCKCHAIN, $i - 1 ) )
            w8io_error( 'set checkpoint_transactions failed' );

        if( !$this->blocks->commit() )
            w8io_error( 'unexpected commit() error' );

        if( $i <= $to )
            return false;

        return array( 'blockchain' => $this, 'from' => $from, 'to' => $to, 'height' => $height );
    }
}