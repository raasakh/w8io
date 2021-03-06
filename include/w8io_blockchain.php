<?php

require_once 'w8io_pairs.php';
require_once 'w8io_nodes.php';

class w8io_blockchain
{
    private $blocks;
    private $checkpoint;
    private $nodes;

    private $last_update = 0;

    public function __construct( $writable = true )
    {
        $this->blocks = new w8io_pairs( W8IO_DB_BLOCKCHAIN, 'blocks', $writable, 'INTEGER PRIMARY KEY|BLOB|0|0', W8IO_CACHE_BLOCKS );
        $this->checkpoint = new w8io_pairs( $this->blocks->get_db(), 'checkpoint', $writable, 'INTEGER PRIMARY KEY|TEXT|0|0' );
        $this->nodes = new w8io_nodes( explode( '|', W8IO_NODES ) );
    }

    public function get_height()
    {
        $height = $this->checkpoint->get_value( W8IO_CHECKPOINT_BLOCKCHAIN, 'i' );

        if( !$height )
            return 0;

        return $height;
    }

    public function get_block( $at )
    {
        return $this->blocks->get_value( $at, 'jz' );
    }

    private function set_block( $block )
    {
        return $this->blocks->set_pair( $block['height'], $block, 'jz' );
    }

    public function update( $trynext = false )
    {
        if( $trynext )
            $this->nodes->trynext();

        $from = $this->get_height();
        $to = $this->nodes->get_height();
        $shift = 0;

        if( !$to )
        {
            w8io_trace( 'w', 'can not get_height()' );
            return false;
        }

        $to -= W8IO_HEIGHT_CORRECTION; // highest stable block
        $height = $to;

        if( $to <= $from ) // no new blocks
        {
            $rollback = false;

            if( !$trynext && $this->last_update && time() - $this->last_update > 300 )
            {
                // rollback detection
                if( W8IO_NETWORK !== 'W' && $to < $from )
                {
                    $local_block = $this->get_block( $to );
                    $nodes_block = $this->nodes->get_block( $to );
                    if( $nodes_block === false )
                    {
                        w8io_trace( 'w', "can not get_block()" );
                        return false;
                    }
        
                    if( $nodes_block['reference'] !== $local_block['signature'] )
                    {
                        w8io_trace( 'w', "rollback detected ($from >> $to)" );
                        $from = $to - 1;
                        $rollback = true;
                    }
                }

                if( !$rollback )
                {
                    w8io_trace( 'w', 'no new blocks for 300 seconds (try next node)' );
                    return $this->update( true );
                }
            }

            if( !$rollback )
                return true;
        }

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

            if( $nodes_block['reference'] === $local_block['signature'] ) // no fork
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
                w8io_trace( 'w', "fork at $from" );

                if( --$from === 0 )
                    break;

                $local_block = $this->get_block( $from );
                $nodes_block = $this->nodes->get_block( $from );
                if( $nodes_block === false )
                {
                    w8io_trace( 'w', 'can not get_block()' );
                    $this->blocks->rollback();
                    return false;
                }

                if( $nodes_block['reference'] === $local_block['reference'] &&
                    $nodes_block['signature'] === $local_block['signature'] )
                {
                    $signature = $nodes_block['signature'];
                    w8io_trace( 'w', "no fork at $from" );
                    break;
                }
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

            if( isset( $signature ) && $nodes_block['reference'] !== $signature )
            {
                w8io_trace( 'w', "fork at $i (break)" );
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

        $this->last_update = time();
        return [ 'blockchain' => $this, 'from' => $from, 'to' => $to, 'height' => $height ];
    }
}
